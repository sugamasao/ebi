<?php
\ebi\Flow::set([
	'error_tempalte'=>'ERROR_TEMP.html',
	'patterns'=>[
		''=>['template'=>'index.html'],
		'hoge'=>['template'=>'index.html'],
		'secure'=>['name'=>'secure','template'=>'secure.html','secure'=>true],
		'nosecure'=>['name'=>'nosecure','template'=>'secure.html'],
	]
]);
