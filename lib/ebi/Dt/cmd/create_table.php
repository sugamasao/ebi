<?php
/**
 * モデルからtableを作成する
 * @param string $model 
 * @param boolean $drop 
 */
foreach(\ebi\Dt::create_table($model,$drop) as $result){
	if($result[0] == -1){
		print('dropped '.$result[1].PHP_EOL);
	}else if($result[0] == 1){
		print('created '.$result[1].PHP_EOL);
	}
}
