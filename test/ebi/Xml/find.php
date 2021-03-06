<?php


$x = new \ebi\Xml("abc","<asd><abc><def><ghi>AAA</ghi><ghi>BBB</ghi><ghi>CCC</ghi></def></abc></asd>");
$i = 0;
foreach($x->find('abc/def/ghi') as $t){
	$i++;
}
eq(3,$i);

$x = new \ebi\Xml("abc","<asd><abc><def><ghi>ABC</ghi><ghi>XYZ</ghi></def></abc></asd>");
eq('ABC',$x->find_get('abc/def/ghi')->value());

$x = new \ebi\Xml("abc","<asd><abc><def><ghi>ABC</ghi><ghi>XYZ</ghi></def></abc></asd>");
eq('XYZ',$x->find_get('abc/def/ghi',1)->value());


$x = new \ebi\Xml("abc","<asd><abc><def><ghi>ABC</ghi><ghi>XYZ</ghi></def></abc></asd>");
eq('XYZ',$x->find_get('abc/def/ghi',1)->value());

$x = new \ebi\Xml("abc","<asd><abc><def><jkl>aaa</jkl><ghi>ABC</ghi><jkl>bbb</jkl><ghi>XYZ</ghi></def></abc></asd>");
eq('XYZ',$x->find_get('abc/def/ghi',1)->value());

$x = new \ebi\Xml("abc","<asd><abc><def><jkl>aaa</jkl><ghi>ABC</ghi><jkl>bbb</jkl><ghi>XYZ</ghi></def></abc></asd>");
eq('bbb',$x->find_get('abc/def/jkl',1)->value());


$x = new \ebi\Xml("abc","<asd><abc><def><jkl>aaa</jkl><ghi>ABC</ghi><jkl>bbb</jkl><ghi>XYZ</ghi></def></abc></asd>");
eq('bbb',$x->find_get('abc/def/ghi|jkl',2)->value());


$x = new \ebi\Xml("abc","<xml><a><x>A</x></a><c><x>C</x></c><b><x>B</x></b></xml>");
eq('C',$x->find_get('b|c/x')->value());



$x = new \ebi\Xml("abc","<xml> <a><b><e>NO1</e></b></a> <a><b><c>A</c></b></a> <a><b><c>B</c></b></a>  <a><b><c>C</c></b></a> </xml>");
eq('A',$x->find_get('a/b/c')->value());
$i = 0;
foreach($x->find('a/b/c') as $f){
	$i++;
}
eq(1,$i);


$x = new \ebi\Xml("abc","<xml> <a><b><e>NO1</e></b></a> <a><b><c>A</c></b></a> <a><b><c>B</c></b></a>  <a><b><c>C</c></b></a> </xml>");
try{
	$x->find_get('a/b/c',1)->value();
	failure();
}catch(\ebi\exception\NotFoundException $e){
}

$x = new \ebi\Xml("abc","<xml> <a><b><e>NO1</e><c>A</c><c>B</c><c>C</c></b></a> </xml>");
eq('C',$x->find_get('a/b/c',2)->value());

$i = 0;
foreach($x->find('a/b/c') as $f){
	$i++;
}
eq(3,$i);




