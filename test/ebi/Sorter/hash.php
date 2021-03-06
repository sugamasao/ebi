<?php

$o = array();
$o[] = array("id"=>1,"abc"=>1);
$o[] = array("id"=>2,"abc"=>3);
$o[] = array("id"=>3,"abc"=>2);
	
usort($o,create_function('$a,$b',sprintf('return ($a["abc"] > $b["abc"]) ? -1 : 1;')));
eq(2,$o[0]["id"]);
eq(3,$o[1]["id"]);
eq(1,$o[2]["id"]);

$objects = array();
$obj["id"] = 1;
$obj["abc"] = 1;
$objects[] = $obj;
	
$obj["id"]  = 2;
$obj["abc"] = 3;
$objects[] = $obj;

$obj["id"]  = 3;
$obj["abc"] = 2;
$objects[] = $obj;
	
eq(3,sizeof($objects));
$sort = \ebi\Sorter::hash($objects,"abc");
eq(3,sizeof($sort));
eq(1,$sort[0]["id"]);
eq(3,$sort[1]["id"]);
eq(2,$sort[2]["id"]);
	
eq(1,$objects[0]["id"]);
eq(2,$objects[1]["id"]);
eq(3,$objects[2]["id"]);

$sort = \ebi\Sorter::hash($objects,"-abc");
eq(3,sizeof($sort));
eq(2,$sort[0]["id"]);
eq(3,$sort[1]["id"]);
eq(1,$sort[2]["id"]);
