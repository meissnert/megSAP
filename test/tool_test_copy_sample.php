<?php

require_once("framework.php");

$name = "copy_sample";
$file = data_folder().$name;

start_test($name);

if (production_ngsd_enabled())
{
	mkdir("Unaligned");

	$out_file = output_folder().$name."_out1Makefile";
	check_exec("php ".src_folder()."/NGS/".$name.".php -ss ".$file."_in1.csv -out ".$out_file);
	check_file($out_file, data_folder().$name."_out1Makefile");

	$out_file = output_folder().$name."_out2Makefile";
	check_exec("php ".src_folder()."/NGS/".$name.".php -ss ".$file."_in2.csv -out ".$out_file);
	check_file($out_file, data_folder().$name."_out2Makefile");

	rmdir("Unaligned");
}

end_test();

?>