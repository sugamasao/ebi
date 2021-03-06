<?php
namespace ebi;
/**
 * O/R Mapper
 * @author tokushima
 */
abstract class Dao extends \ebi\Object{
	use \ebi\Plugin;
	
	private static $_dao_ = array();
	private static $_cnt_ = 0;	

	private $_has_hierarchy_ = 1;
	private $_class_id_;
	private $_hierarchy_;

	private static $_co_anon_ = array();
	private static $_connections_ = array();
	private static $recording_query = false;
	private static $record_query = array();	

	/**
	 * コネクション一覧
	 * @return Db[]
	 */
	public static function connections(){
		$connections = array();
		foreach(self::$_connections_ as $n => $con){
			$connections[$n] = $con;
		}
		return $connections;
	}
	public static function connection($class){
		if(!isset(self::$_connections_[self::$_co_anon_[$class][0]])){
			throw new \RuntimeException('unable to connect to '.$class);
		}
		return self::$_connections_[self::$_co_anon_[$class][0]];
	}
	/**
	 * すべての接続でロールバックする
	 */
	public static function rollback_all(){
		foreach(self::connections() as $con) $con->rollback();
	}
	/**
	 * すべての接続でコミットする
	 */
	public static function commit_all(){
		foreach(self::connections() as $con) $con->commit();
	}
	private static function get_con($database,$class){
		$def = \ebi\Conf::get('connection');
		if(!isset(self::$_connections_[$database])){
			try{
				if(is_array($def[$database])){
					if(isset($def[$database]['con'])){
						self::get_con($def[$database]['con'],$class);
						self::$_connections_[$database] = self::$_connections_[$def[$database]['con']];
						return $def[$database];
					}
					self::$_connections_[$database] = new \ebi\Db($def[$database]);
				}
			}catch(\Exception $e){
				throw new \RuntimeException($class.'('.$database.'): '.$e->getMessage());
			}
		}
		return $def[$database];
	}
	protected function __new__(){
		if(func_num_args() == 1){
			foreach(func_get_arg(0) as $n => $v){
				switch($n){
					case '_has_hierarchy_':
					case '_class_id_':
					case '_hierarchy_':
						$this->{$n} = $v;
						break;
					default:
				}
			}
		}
		$p = get_class($this);
		if(!isset($this->_class_id_)) $this->_class_id_ = $p;		
		if(isset(self::$_dao_[$this->_class_id_])){
			foreach(self::$_dao_[$this->_class_id_]->_has_dao_ as $name => $dao) $this->{$name}($dao);
			return;
		}
		if(!isset(self::$_co_anon_[$p])){
			$table_info = \ebi\Annotation::decode($p,'table');
			$anon = array(null // con
							,(isset($table_info['name']) ? $table_info['name'] : null)
							,(isset($table_info['create']) ? $table_info['create'] : true)
							,(isset($table_info['update']) ? $table_info['update'] : true)
							,(isset($table_info['delete']) ? $table_info['delete'] : true)
							,null // prefix
							,false // upper
							,false // lower
						);
			$conf = explode("\\",$p);
			$def = \ebi\Conf::get('connection');
			while(!isset($def[implode('.',$conf)]) && !empty($conf)) array_pop($conf);
			if(empty($conf) && !isset($def['*'])) throw new \ebi\exception\ConnectionException('could not find the connection settings `'.$p.'`');
			$anon[0] = empty($conf) ? '*' : implode('.',$conf);
			
			if(empty($anon[1])){
				$table_class = $p;
				$parent_class = get_parent_class($p);
				$ref = new \ReflectionClass($parent_class);
				while(true){
					$ref = new \ReflectionClass($parent_class);
					if(__CLASS__ == $parent_class || $ref->isAbstract()) break;
					$table_class = $parent_class;
					$parent_class = get_parent_class($parent_class);
				}
				$table_class = preg_replace("/^.*\\\\(.+)$/","\\1",$table_class);
				$anon[1] = strtolower($table_class[0]);
				for($i=1;$i<strlen($table_class);$i++) $anon[1] .= (ctype_lower($table_class[$i])) ? $table_class[$i] : '_'.strtolower($table_class[$i]);
			}
			$config = self::get_con($anon[0],$p);
			if(!isset(self::$_connections_[$anon[0]])) throw new \RuntimeException('connection fail '.str_replace("\\",'.',get_class($this)));
			static::set_class_plugin(self::$_connections_[$anon[0]]->connector());
			$anon[5] = isset($config['prefix']) ? $config['prefix'] : '';
			$anon[6] = (isset($config['upper']) && $config['upper'] === true);
			$anon[7] = (isset($config['lower']) && $config['lower'] === true);
			self::$_co_anon_[$p] = $anon;
			self::$_co_anon_[$p][1] = self::set_table_name(self::$_co_anon_[$p][1],$p);
		}		
		$has_hierarchy = (isset($this->_hierarchy_)) ? $this->_hierarchy_ - 1 : $this->_has_hierarchy_;
		$root_table_alias = 't'.self::$_cnt_++;
		$_columns_ = $_self_columns_ = $_where_columns_ = $_conds_ = $_join_conds_ = $_alias_ = $_has_many_conds_ = $_has_dao_ = array();
		
		foreach(array_keys(get_object_vars($this)) as $name){
			if($name[0] != '_' && $this->prop_anon($name,'extra') !== true){
				$anon_cond = $this->prop_anon($name,'cond');
				$column_type = $this->prop_anon($name,'type');
				if(empty($column_type)){
					if($name == 'id'){
						$this->prop_anon($name,'type','serial',true);
					}else if($name == 'created_at' || $name == 'create_date'){
						$this->prop_anon($name,'type','timestamp',true);
						$this->prop_anon($name,'auto_now_add',true,true);
					}else if($name == 'updated_at' || $name == 'update_date'){
						$this->prop_anon($name,'type','timestamp',true);
						$this->prop_anon($name,'auto_now',true,true);
					}else if($name == 'code'){
						$this->prop_anon($name,'type','string',true);
						$this->prop_anon($name,'auto_code_add',true,true);
					}
					$column_type = $this->prop_anon($name,'type','string');
				}
				if($this->prop_anon($name,'type') == 'serial'){
					$this->prop_anon($name,'primary',true,true);
				}				
				$column = new \ebi\Column();
				$column->name($name);
				$column->column($this->prop_anon($name,'column',$name));
				$column->column_alias('c'.self::$_cnt_++);

				if($anon_cond === null){
					if(ctype_upper($column_type[0]) && class_exists($column_type) && is_subclass_of($column_type,__CLASS__)){
						throw new \RuntimeException('undef '.$name.' annotation `cond`');
					}
					$column->table($this->table());
					$column->table_alias($root_table_alias);
					$column->primary($this->prop_anon($name,'primary',false) || $column_type === 'serial');
					$column->auto($column_type === 'serial');
					$_columns_[] = $column;
					$_self_columns_[$name] = $column;
					$_alias_[$column->column_alias()] = $name;
				}else if(false !== strpos($anon_cond,'(')){
					$is_has = (class_exists($column_type) && is_subclass_of($column_type,__CLASS__));
					$is_has_many = ($is_has && $this->prop_anon($name,'attr') === 'a');
					if((!$is_has || $has_hierarchy > 0) && preg_match("/^(.+)\((.*)\)(.*)$/",$anon_cond,$match)){
						list(,$self_var,$conds_string,$has_var) = $match;
						$conds = array();
						$ref_table = $ref_table_alias = null;
						if(!empty($conds_string)){
							foreach(explode(',',$conds_string) as $cond){
								$tcc = explode('.',$cond,3);
								switch(sizeof($tcc)){
									case 1:
										$conds[] = \ebi\Column::cond_instance($tcc[0],'c'.self::$_cnt_++,$this->table(),$root_table_alias);
										break;
									case 2:
										list($t,$c1) = $tcc;
										$ref_table = self::set_table_name($t,$p);
										$ref_table_alias = 't'.self::$_cnt_++;
										$conds[] = \ebi\Column::cond_instance($c1,'c'.self::$_cnt_++,$ref_table,$ref_table_alias);
										break;
									case 3:
										list($t,$c1,$c2) = $tcc;
										$ref_table = self::set_table_name($t,$p);
										$ref_table_alias = 't'.self::$_cnt_++;
										$conds[] = \ebi\Column::cond_instance($c1,'c'.self::$_cnt_++,$ref_table,$ref_table_alias);
										$conds[] = \ebi\Column::cond_instance($c2,'c'.self::$_cnt_++,$ref_table,$ref_table_alias);
										break;
									default:
										throw new \LogicException('annotation error : `'.$name.'`');
								}
							}
						}
						if($is_has_many){
							if(empty($has_var)) throw new \LogicException('annotation error : `'.$name.'`');
							$dao = new $column_type(array('_class_id_'=>$p.'___'.self::$_cnt_++));
							$_has_many_conds_[$name] = array($dao,$has_var,$self_var);
						}else{
							$self_db = true;
							if($is_has){
								if(empty($has_var)) throw new \LogicException('annotation error : `'.$name.'`');
								$dao = new $column_type(array('_class_id_'=>($p.'___'.self::$_cnt_++),'_hierarchy_'=>$has_hierarchy));
								$this->{$name}($dao);
								if($dao->table() == $this->table()){
									$_has_dao_[$name] = $dao;
									$_columns_ = array_merge($_columns_,$dao->columns());
									$_conds_ = array_merge($_conds_,$dao->conds());
									$this->prop_anon($name,'has',true,true);
									foreach($dao->columns() as $column) $_alias_[$column->column_alias()] = $name;
									$has_column = $dao->base_column($dao->columns(),$has_var);
									$conds[] = \ebi\Column::cond_instance($has_column->column(),'c'.self::$_cnt_++,$has_column->table(),$has_column->table_alias());
								}else{
									$_has_many_conds_[$name] = array($dao,$has_var,$self_var);
									$self_db = false;
								}
							}else{
								$column->table($ref_table);
								$column->table_alias($ref_table_alias);
								if(!$this->prop_anon($name,'join',false)) $_columns_[] = $column;
								$_where_columns_[$name] = $column;
								$_alias_[$column->column_alias()] = $name;
							}
							if($self_db){
								array_unshift($conds,\ebi\Column::cond_instance($self_var,'c'.self::$_cnt_++,$this->table(),$root_table_alias));
								if(sizeof($conds) % 2 != 0) throw new \RuntimeException($name.'['.$column_type.'] is illegal condition');
								if($this->prop_anon($name,'join',false)){
									$this->prop_anon($name,'get',false,true);
									$this->prop_anon($name,'set',false,true);
									for($i=0;$i<sizeof($conds);$i+=2) $_join_conds_[$name][] = array($conds[$i],$conds[$i+1]);
								}else{
									for($i=0;$i<sizeof($conds);$i+=2) $_conds_[] = array($conds[$i],$conds[$i+1]);
								}
							}
						}
					}
				}else if($anon_cond[0] === '@'){
					$c = $this->base_column($_columns_,substr($anon_cond,1));
					$column->table($c->table());
					$column->table_alias($c->table_alias());
					$_columns_[] = $column;
					$_where_columns_[$name] = $column;
					$_alias_[$column->column_alias()] = $name;
				}
			}
		}
		self::$_dao_[$this->_class_id_] = (object)array(
														'_columns_'=>$_columns_,
														'_self_columns_'=>$_self_columns_,
														'_where_columns_'=>$_where_columns_,
														'_conds_'=>$_conds_,
														'_join_conds_'=>$_join_conds_,
														'_alias_'=>$_alias_,
														'_has_dao_'=>$_has_dao_,
														'_has_many_conds_'=>$_has_many_conds_
														);
	}
	private static function set_table_name($name,$class){
		$name = self::$_co_anon_[$class][5].$name;
		if(self::$_co_anon_[$class][6]) $name = strtoupper($name);
		if(self::$_co_anon_[$class][7]) $name = strtolower($name);
		return $name;
	}
	private function base_column($_columns_,$name){
		foreach($_columns_ as $c){
			if($c->is_base() && $c->name() === $name) return $c;
		}
		throw new \RuntimeException('undef var `'.$name.'`');
	}
	/**
	 * 全てのColumnの一覧を取得する
	 * @return \ebi\Column[]
	 */
	public function columns(){
		return self::$_dao_[$this->_class_id_]->_columns_;
	}
	/**
	 * 主のColumnの一覧を取得する
	 * @return \ebi\Column[]
	 */
	public function self_columns($all=false){
		if($all) return array_merge(self::$_dao_[$this->_class_id_]->_where_columns_,self::$_dao_[$this->_class_id_]->_self_columns_);
		return self::$_dao_[$this->_class_id_]->_self_columns_;
	}
	/**
	 * primaryのColumnの一覧を取得する
	 * @return \ebi\Column[]
	 */
	public function primary_columns(){
		$result = array();
		foreach(self::$_dao_[$this->_class_id_]->_self_columns_ as $column){
			if($column->primary()) $result[$column->name()] = $column;
		}
		return $result;
	}
	/**
	 * 必須の条件を取得する
	 * @return array array(\ebi\Column,\ebi\Column)
	 */
	public function conds(){
		return self::$_dao_[$this->_class_id_]->_conds_;
	}
	/**
	 * join時の条件を取得する
	 * @return array array(\ebi\Column,\ebi\Column)
	 */
	public function join_conds($name){
		return (isset(self::$_dao_[$this->_class_id_]->_join_conds_[$name])) ? self::$_dao_[$this->_class_id_]->_join_conds_[$name] : array();
	}
	/**
	 * 結果配列から値を自身にセットする
	 * @param $resultset array
	 * @return integer
	 */
	public function parse_resultset($resultset){
		foreach($resultset as $alias => $value){
			if(isset(self::$_dao_[$this->_class_id_]->_alias_[$alias])){
				if(self::$_dao_[$this->_class_id_]->_alias_[$alias] == 'ref1') $this->prop_anon(self::$_dao_[$this->_class_id_]->_alias_[$alias],'has',true);

				if($this->prop_anon(self::$_dao_[$this->_class_id_]->_alias_[$alias],'has') === true){
					$this->{self::$_dao_[$this->_class_id_]->_alias_[$alias]}()->parse_resultset(array($alias=>$value));
				}else{
					$this->{self::$_dao_[$this->_class_id_]->_alias_[$alias]}($value);
				}
			}
		}
		if(!empty(self::$_dao_[$this->_class_id_]->_has_many_conds_)){
			foreach(self::$_dao_[$this->_class_id_]->_has_many_conds_ as $name => $conds){
				foreach($conds[0]::find(Q::eq($conds[1],$this->{$conds[2]}())) as $dao) $this->{$name}($dao);
			}
		}
	}
	/**
	 * テーブル名を取得
	 * @return string
	 */
	public function table(){
		return self::$_co_anon_[get_class($this)][1];
	}
	protected function __find_conds__(){
		return Q::b();
	}
	protected function __before_create__(){}
	protected function __after_create__(){}
	
	protected function __before_update__(){}
	protected function __after_update__(){}
	
	protected function __after_delete__(){}
	protected function __before_delete__(){}

	protected function __before_save__(){}
	protected function __after_save__(){}
	
	/**
	 * 発行したSQLの記録を開始する
	 */
	public static function start_record(){
		$query = self::$record_query;
		self::$recording_query = true;
		self::$record_query = array();
		return $query;
	}
	/**
	 * 発行したSQLの記録を終了する
	 */
	public static function stop_record(){
		self::$recording_query = false;
		return self::$record_query;	
	}
	/**
	 * 記録したSQLを取得する
	 * @return array
	 */
	public static function recorded_query(){
		return self::$record_query;
	}
	/**
	 * クエリを実行する
	 * @param Daq $daq
	 * @throws RuntimeException
	 * @return \PDOStatement
	 */
	public function query(\ebi\Daq $daq){
		if(self::$recording_query) self::$record_query[] = array($daq->sql(),$daq->ar_vars());
		$statement = self::connection(get_class($this))->prepare($daq->sql());
		if($statement === false) throw new \RuntimeException('prepare fail: '.$daq->sql());
		$statement->execute($daq->ar_vars());
		return $statement;
	}
	private function update_query(\ebi\Daq $daq){
		$statement = $this->query($daq);
		$errors = $statement->errorInfo();
		if(isset($errors[1])){
			static::rollback();
			throw new \RuntimeException('['.$errors[1].'] '.(isset($errors[2]) ? $errors[2] : '').PHP_EOL.'( '.$daq->sql().' )');
		}
		return $statement->rowCount();
	}
	private function func_query(\ebi\Daq $daq,$is_list=false){
		$statement = $this->query($daq);
		$errors = $statement->errorInfo();
		if(isset($errors[1])){
			throw new \RuntimeException('['.$errors[1].'] '.(isset($errors[2]) ? $errors[2] : '').PHP_EOL.'( '.$daq->sql().' )');
		}
		if($statement->columnCount() == 0) return ($is_list) ? array() : null;
		return ($is_list) ? $statement->fetchAll(\PDO::FETCH_ASSOC) : $statement->fetchAll(\PDO::FETCH_COLUMN,0);
	}
	private function save_verify_primary_unique(){
		$q = new \ebi\Q();
		$primary = false;
		foreach($this->primary_columns() as $column){
			$value = $this->{$column->name()}();
			if($this->prop_anon($column->name(),'type') === 'serial'){
				$primary = false;
				break;
			}
			$q->add(Q::eq($column->name(),$value));
			$primary = true;
		}
		if($primary && static::find_count($q) > 0){
			throw new \ebi\exception\UniqueException('duplicate entry',$this);
		}
	}
	/**
	 * 値の妥当性チェックを行う
	 */
	public function validate(){
		foreach($this->self_columns() as $name => $column){
			$value = $this->{$name}();
			$e_require = false;

			if($this->prop_anon($name,'require') === true && ($value === '' || $value === null)){
				\ebi\Exceptions::add(new \ebi\exception\RequiredException($name.' required'),$name);
				$e_require = true;
			}
			if(!$e_require && $value !== null){
				switch($this->prop_anon($name,'type')){
					case 'number':
					case 'integer':
						if($this->prop_anon($name,'min') !== null && (float)$this->prop_anon($name,'min') > $value){
							\ebi\Exceptions::add(new \ebi\exception\LengthException($name.' less than minimum'),$name);
						}
						if($this->prop_anon($name,'max') !== null && (float)$this->prop_anon($name,'max') < $value){
							\ebi\Exceptions::add(new \ebi\exception\LengthException($name.' exceeds maximum'),$name);
						}
						break;
					case 'text':
					case 'string':
					case 'alnum':
						if($this->prop_anon($name,'min') !== null && (int)$this->prop_anon($name,'min') > mb_strlen($value)){
							\ebi\Exceptions::add(new \ebi\exception\LengthException($name.' less than minimum'),$name);
						}
						if($this->prop_anon($name,'max') !== null && (int)$this->prop_anon($name,'max') < mb_strlen($value)){
							\ebi\Exceptions::add(new \ebi\exception\LengthException($name.' exceeds maximum'),$name);
						}
						break;
				}
			}
			$unique_together = $this->prop_anon($name,'unique_together');
			if($value !== '' && $value !== null && ($this->prop_anon($name,'unique') === true || !empty($unique_together))){
				$uvalue = $value;
				$q = array(Q::eq($name,$uvalue));
				if(!empty($unique_together)){
					foreach((is_array($unique_together) ? $unique_together : array($unique_together)) as $c){
						$q[] = Q::eq($c,$this->{$c}());
					}
				}
				foreach($this->primary_columns() as $primary){
					if(null !== $this->{$primary->name()}) $q[] = Q::neq($primary->name(),$this->{$primary->name()});
				}
				if(0 < call_user_func_array(array(get_class($this),'find_count'),$q)){
					\ebi\Exceptions::add(new \ebi\exception\UniqueException($name.' unique'),$name);
				}
			}
			$master = $this->prop_anon($name,'master');
			if(!empty($master)){
				$master = str_replace('.',"\\",$master);
				if($master[0] !== "\\") $master = "\\".$master;
				try{
					$r = new \ReflectionClass($master);
				}catch(\ReflectionException $e){
					$self = new \ReflectionClass(get_class($this));
					$r = new \ReflectionClass("\\".$self->getNamespaceName().$master);
				}
				$mo = $r->newInstanceArgs();
				$primarys = $mo->primary_columns();
				if(empty($primarys) || 0 === call_user_func_array(array($mo,'find_count'),array(Q::eq(key($primarys),$this->{$name})))){
					\ebi\Exceptions::add(new \ebi\exception\NotFoundException($name.' master not found'),$name);
				}
			}
			try{
				if($this->{'verify_'.$column->name()}() === false){
					\ebi\Exceptions::add(new \ebi\exception\VerifyException($column->name().' verification failed'),$column->name());
				}
			}catch(\ebi\Exceptions $e){
			}catch(\Exception $e){
				\ebi\Exceptions::add($e,$column->name());				
			}
		}
		\ebi\Exceptions::throw_over();
	}
	protected function which_aggregator($exe,array $args,$is_list=false){
		$target_name = $gorup_name = array();
		if(isset($args[0]) && is_string($args[0])){
			$target_name = array_shift($args);
			if(isset($args[0]) && is_string($args[0])) $gorup_name = array_shift($args);
		}
		$query = new \ebi\Q();
		if(!empty($args)) call_user_func_array(array($query,'add'),$args);
		$daq = static::call_class_plugin_funcs($exe.'_sql',$this,$target_name,$gorup_name,$query);
		return $this->func_query($daq,$is_list);
	}
	private static function exec_aggregator_result_cast($dao,$target_name,$value,$cast){
		switch($cast){
 			case 'float': return (float)$value;
 			case 'integer': return (int)$value;
		}
		$dao->{$target_name}($value);
		return $dao->{$target_name}();
	}
	private static function exec_aggregator($exec,$target_name,$args,$cast=null){
		$dao = new static();
		$args[] = $dao->__find_conds__();
		$result = $dao->which_aggregator($exec,$args);
		return static::exec_aggregator_result_cast($dao,$target_name,current($result),$cast);
	}
	private static function exec_aggregator_by($exec,$target_name,$gorup_name,$args,$cast=null){
		if(empty($target_name) || !is_string($target_name)) throw new \RuntimeException('undef target_name');
		if(empty($gorup_name) || !is_string($gorup_name)) throw new \RuntimeException('undef group_name');
		$dao = new static();
		$args[] = $dao->__find_conds__();
		$results = array();
		foreach($dao->which_aggregator($exec,$args,true) as $value){
			$dao->{$gorup_name}($value['key_column']);
			$results[$dao->{$gorup_name}()] = static::exec_aggregator_result_cast($dao,$target_name,$value['target_column'],$cast);
		}
		ksort($results);
		return $results;
	}
	/**
	 * カウントを取得する
	 * @paaram string $target_name 対象となるプロパティ
	 * @return integer
	 */
	public static function find_count($target_name=null){
		$args = func_get_args();
		return (int)static::exec_aggregator('count',$target_name,$args,'integer');
	}
	/**
	 * グルーピングしてカウントを取得する
	 * @param string $target_name 対象となるプロパティ
	 * @param string $gorup_name グルーピングするプロパティ名
	 * @return integer{}
	 */
	public static function find_count_by($target_name,$gorup_name){
		$args = func_get_args();
		return static::exec_aggregator_by('count',$target_name,$gorup_name,$args);
	}
	/**
	 * 合計を取得する
	 * @param string $target_name 対象となるプロパティ
	 * @return number
	 */
	public static function find_sum($target_name){
		$args = func_get_args();
		return static::exec_aggregator('sum',$target_name,$args);
	}
	/**
	 * グルーピングした合計を取得する
	 * @param string $target_name 対象となるプロパティ
	 * @param string $gorup_name グルーピングするプロパティ名
	 * @return integer{}
	 */
	public static function find_sum_by($target_name,$gorup_name){
		$args = func_get_args();
		return static::exec_aggregator_by('sum',$target_name,$gorup_name,$args);
	}
	/**
	 * 最大値を取得する
	 *
	 * @param string $target_name 対象となるプロパティ
	 * @return number
	 */
	public static function find_max($target_name){
		$args = func_get_args();
		return static::exec_aggregator('max',$target_name,$args);
	}
	/**
	 * グルーピングして最大値を取得する
	 * @param string $target_name 対象となるプロパティ
	 * @param string $gorup_name グルーピングするプロパティ名
	 * @return number
	 */
	public static function find_max_by($target_name,$gorup_name){
		$args = func_get_args();
		return static::exec_aggregator_by('max',$target_name,$gorup_name,$args);
	}
	/**
	 * 最小値を取得する
	 * @param string $target_name 対象となるプロパティ
	 * @param string $gorup_name グルーピングするプロパティ名
	 * @return number
	 */
	public static function find_min($target_name){
		$args = func_get_args();
		return static::exec_aggregator('min',$target_name,$args);
	}
	/**
	 * グルーピングして最小値を取得する
	 * @param string $target_name 対象となるプロパティ
	 * @param string $gorup_name グルーピングするプロパティ名
	 * return integer{}
	 */
	public static function find_min_by($target_name,$gorup_name){
		$args = func_get_args();
		return static::exec_aggregator_by('min',$target_name,$gorup_name,$args);
	}
	/**
	 * 平均を取得する
	 * @param string $target_name 対象となるプロパティ
	 * @return number
	 */
	public static function find_avg($target_name){
		$args = func_get_args();
		return static::exec_aggregator('avg',$target_name,$args,'float');
	}
	/**
	 * グルーピングして平均を取得する
	 * @param string $target_name 対象となるプロパティ
	 * @param string $gorup_name グルーピングするプロパティ名
	 * @return number{}
	 */
	public static function find_avg_by($target_name,$gorup_name){
		$args = func_get_args();
		return static::exec_aggregator_by('avg',$target_name,$gorup_name,$args,'float');
	}
	/**
	 * distinctした一覧を取得する
	 *
	 * @param string $target_name 対象となるプロパティ
	 * @return mixed[]
	 */
	public static function find_distinct($target_name){
		$args = func_get_args();
		$dao = new static();
		$args[] = $dao->__find_conds__();
		$results = $dao->which_aggregator('distinct',$args);
		return $results;
	}
	/**
	 * 検索結果をひとつ取得する
	 * @return $this
	 */
	public static function find_get(){
		$args = func_get_args();
		$dao = new static();
		$query = new \ebi\Q();
		$query->add($dao->__find_conds__());
		$query->add(new \ebi\Paginator(1,1));
		if(!empty($args)) call_user_func_array(array($query,'add'),$args);
		foreach(self::get_statement_iterator($dao,$query) as $d) return $d;
		throw new \ebi\exception\NotFoundException('not found');
	}
	/**
	 * サブクエリを取得する
	 * @param $name 対象のプロパティ
	 * @return Daq
	 */
	public static function find_sub($name){
		$args = func_get_args();
		array_shift($args);
		$dao = new static();
		$query = new \ebi\Q();
		$query->add($dao->__find_conds__());

		if(!empty($args)) call_user_func_array(array($query,'add'),$args);
		if(!$query->is_order_by()) $query->order($name);
		$paginator = $query->paginator();
		if($paginator instanceof \ebi\Paginator){
			if($query->is_order_by()) $paginator->order($query->in_order_by(0)->ar_arg1(),$query->in_order_by(0)->type() == Q::ORDER_ASC);
			$paginator->total(call_user_func_array(array(get_called_class(),'find_count'),$args));
			if($paginator->total() == 0) return array();
		}
		/**
		 * SELECT文の生成
		 * @param self $dao
		 * @param Q $query
		 * @param ebi.Paginator $paginator
		 * @param string $name
		 * @return Daq
		 */
		return static::call_class_plugin_funcs('select_sql',$dao,$query,$paginator,$name);
	}
	private static function get_statement_iterator($dao,$query){
		if(!$query->is_order_by()){
			foreach($dao->primary_columns() as $column) $query->order($column->name());
		}
		/**
		 * SELECT文の生成
		 * @param self $dao
		 * @param Q $query
		 * @param ebi.Paginator $paginator
		 * @param string $name
		 * @return Daq
		 */
		$daq = static::call_class_plugin_funcs('select_sql',$dao,$query,$query->paginator());
		$statement = $dao->query($daq);
		$errors = $statement->errorInfo();
		if(isset($errors[1])){
			throw new \RuntimeException('['.$errors[1].'] '.(isset($errors[2]) ? $errors[2] : ''));
		}
		return new \ebi\StatementIterator($dao,$statement);
	}
	/**
	 * 検索を実行する
	 * @return StatementIterator
	 */
	public static function find(){
		$args = func_get_args();
		$dao = new static();
		$query = new \ebi\Q();
		$query->add($dao->__find_conds__());
		if(!empty($args)) call_user_func_array(array($query,'add'),$args);
		
		$paginator = $query->paginator();
		if($paginator instanceof \ebi\Paginator){
			if($query->is_order_by()) $paginator->order($query->in_order_by(0)->ar_arg1(),$query->in_order_by(0)->type() == Q::ORDER_ASC);
			$paginator->total(call_user_func_array(array(get_called_class(),'find_count'),$args));
			if($paginator->total() == 0) return array();
		}
		return static::get_statement_iterator($dao,$query);
	}
	/**
	 * 検索結果をすべて取得する
	 * @return self[]
	 */
	public static function find_all(){
		$args = func_get_args();
		$result = array();
		foreach(call_user_func_array(array(get_called_class(),'find'),$args) as $p) $result[] = $p;
		return $result;
	}
	/**
	 * コミットする
	 */
	public static function commit(){
		self::connection(get_called_class())->commit();
	}
	/**
	 * ロールバックする
	 */
	public static function rollback(){
		self::connection(get_called_class())->rollback();
	}
	/**
	 * 条件により削除する
	 * before/after/verifyは実行されない
	 * @return integer 実行した件数
	 */
	public static function find_delete(){
		$args = func_get_args();
		$dao = new static();
		if(!self::$_co_anon_[get_class($dao)][4]) throw new \ebi\exception\BadMethodCallException('delete is not permitted');
		$query = new \ebi\Q();
		if(!empty($args)) call_user_func_array(array($query,'add'),$args);
		/**
		 * delete文の生成
		 * @param self $this
		 */
		$daq = static::call_class_plugin_funcs('find_delete_sql',$dao,$query);
		return $dao->update_query($daq);
	}
	/**
	 * DBから削除する
	 */
	public function delete(){
		if(!self::$_co_anon_[get_class($this)][4]) throw new \ebi\exception\BadMethodCallException('delete is not permitted');
		$this->__before_delete__();
		/**
		 * delete文の生成
		 * @param self $this
		 */
		$daq = static::call_class_plugin_funcs('delete_sql',$this);
		if($this->update_query($daq) == 0) throw new \ebi\exception\NotFoundException('delete failed');
		$this->__after_delete__();
	}
	/**
	 * 指定のプロパティにユニークコードをセットする
	 * @param string $prop_name
	 * @param integer $size
	 * @return string 生成されたユニークコード
	 */
	public function set_unique_code($prop_name,$size=null){
		$code = '';
		$max = (!empty($size)) ? $size : $this->prop_anon($prop_name,'max',32);
		$ctype = $this->prop_anon($prop_name,'ctype','alnum');
		if($ctype != 'alnum' && $ctype != 'alpha' && $ctype != 'digit') throw new \LogicException('unexpected ctype');
		$char = '';
		if($ctype != 'digit'){
		 	if($this->prop_anon($prop_name,'upper',false) === true) $char .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		 	if($this->prop_anon($prop_name,'lower',true) === true) $char .= 'abcdefghijklmnopqrstuvwxyz';
		}
		if($ctype == 'alnum' || $ctype == 'digit'){
			$char .= '0123456789';
		}
		$charl = strlen($char) - 1;

		while($code == '' || static::find_count(Q::eq($prop_name,$code)) > 0){
			for($code='',$i=0;$i<$max;$i++) $code .= $char[mt_rand(0,$charl)];
			$this->{$prop_name}($code);
			if(!$this->{'verify_'.$prop_name}()){
				$code = '';
			}
		}
		return $code;
	}
	/**
	 * DBへ保存する
	 */
	public function save(){
		$q = new \ebi\Q();
		$new = false;
		foreach($this->primary_columns() as $column){
			$value = $this->{$column->name()}();
			if($this->prop_anon($column->name(),'type') === 'serial' && empty($value)){
				$new = true;
				break;
			}
			$q->add(Q::eq($column->name(),$value));
		}
		$self = get_class($this);
		if(!$new && $self::find_count($q) === 0) $new = true;
		foreach($this->self_columns() as $column){
			if($this->prop_anon($column->name(),'auto_now') === true){
				switch($this->prop_anon($column->name(),'type')){
					case 'timestamp':
					case 'date': $this->{$column->name()}(time()); break;
					case 'intdate': $this->{$column->name()}(date('Ymd')); break;
				}
			}else if($new && ($this->{$column->name()}() === null || $this->{$column->name()}() === '')){
				if($this->prop_anon($column->name(),'type') == 'string' && $this->prop_anon($column->name(),'auto_code_add') === true){
					$this->set_unique_code($column->name());
				}else if($this->prop_anon($column->name(),'auto_now_add') === true){
					switch($this->prop_anon($column->name(),'type')){
						case 'timestamp':
						case 'date': $this->{$column->name()}(time()); break;
						case 'intdate': $this->{$column->name()}(date('Ymd')); break;
					}
				}else if($this->prop_anon($column->name(),'auto_future_add') === true){
					$future = \ebi\Conf::get('future_date','2038/01/01 00:00:00');
					$time = strtotime($future);
					switch($this->prop_anon($column->name(),'type')){
						case 'timestamp':
						case 'date':
							$this->{$column->name()}($time);
							break;
						case 'intdate': $this->{$column->name()}(date('Ymd',$time)); break;
					}
				}
			}
		}
		if($new){
			if(!self::$_co_anon_[$self][2]) throw new \ebi\exception\BadMethodCallException('create save is not permitted');
			$this->__before_save__();
			$this->__before_create__();
			$this->save_verify_primary_unique();
			$this->validate();
			/**
			 * createを実行するSQL文の生成
			 * @param self $this
			 * @return Daq
			 */
			$daq = $self::call_class_plugin_funcs('create_sql',$this);
			if($this->update_query($daq) == 0) throw new \RuntimeException('create failed');
			if($daq->is_id()){
				/**
				 * AUTOINCREMENTの値を取得するSQL文の生成
				 * @param self $this
				 * @return integer
				 */
				$result = $this->func_query(static::call_class_plugin_funcs('last_insert_id_sql',$this));
				if(empty($result)) throw new \RuntimeException('create failed');
				$this->{$daq->id()}($result[0]);
			}
			$this->__after_create__();
			$this->__after_save__();
		}else{
			if(!self::$_co_anon_[$self][3]) throw new \ebi\exception\BadMethodCallException('update save is not permitted');
			$this->__before_save__();
			$this->__before_update__();
			$this->validate();
			$args = func_get_args();
			$query = new \ebi\Q();
			if(!empty($args)) call_user_func_array(array($query,'add'),$args);
			/**
			 * updateを実行するSQL文の生成
			 * @param self $this
			 * @return Daq
			 */
			$daq = $self::call_class_plugin_funcs('update_sql',$this,$query);
			$affected_rows = $this->update_query($daq);
			if($affected_rows === 0 && !empty($args)) throw new \ebi\exception\NoRowsAffectedException();
			$this->__after_update__();
			$this->__after_save__();
		}
		return $this;
	}
	/**
	 * DBの値と同じにする
	 * @return $this
	 */
	public function sync(){
		$query = new \ebi\Q();
		$query->add(new \ebi\Paginator(1,1));
		foreach($this->primary_columns() as $column) $query->add(Q::eq($column->name(),$this->{$column->name()}()));
		foreach(self::get_statement_iterator($this,$query) as $dao){
			foreach(get_object_vars($dao) as $k => $v){
				if($k[0] != '_') $this->{$k}($v);
			}
			return $this;
		}
		throw new \ebi\exception\NotFoundException('synchronization failed');
	}
	/**
	 * 配列からプロパティに値をセットする
	 * @param mixed{} $arg
	 * @return $this
	 */
	public function set_props($arg){
		if(isset($arg) && (is_array($arg) || (is_object($arg) && ($arg instanceof \Traversable)))){
			$vars = get_object_vars($this);
			foreach($arg as $name => $value){
				if($name[0] != '_' && array_key_exists($name,$vars)){
					try{
						$this->{$name}($value);
					}catch(\Exception $e){
						\ebi\Exceptions::add($e,$name);
					}
				}
			}
			\ebi\Exceptions::throw_over();
		}
		return $this;
	}
	/**
	 * @return boolean
	 */
	protected function ___verify___(){
		return true;
	}
	/**
	 * テーブルの作成
	 * @throws RuntimeException
	 */
	public static function create_table(){
		$dao = new static();
		$daq = new \ebi\Daq(static::call_class_plugin_funcs('exists_table_sql',$dao));
		$count = current($dao->func_query($daq));
		if($count == 0){
			$daq = new \ebi\Daq(static::call_class_plugin_funcs('create_table_sql',$dao));
			$dao->func_query($daq);
			$dao->commit();
			return true;
		}
		return false;
	}
	/**
	 * テーブルの削除
	 * @throws RuntimeException
	 */
	public static function drop_table(){
		$dao = new static();
		$daq = new \ebi\Daq(static::call_class_plugin_funcs('exists_table_sql',$dao));
		$count = current($dao->func_query($daq));
		if($count == 1){
			$daq = new \ebi\Daq(static::call_class_plugin_funcs('drop_table_sql',$dao));
			$dao->func_query($daq);
			$dao->commit();
			return true;
		}
		return false;
	}
}
