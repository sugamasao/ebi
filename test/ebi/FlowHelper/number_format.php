<?php
$t = new \ebi\FlowHelper();
eq("123,456,789",$t->number_format("123456789"));
eq("123,456,789.020",$t->number_format("123456789.02",3));
eq("123,456,789",$t->number_format("123456789.02"));

