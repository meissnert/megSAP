<?php
require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../src/Common/all.php");

/// Returns the tool test data folder
function data_folder()
{
	return dirname($_SERVER['SCRIPT_FILENAME'])."/data/";
}

/// Returns the tool test data folder for DB tests
function data_db_folder()
{
	return dirname($_SERVER['SCRIPT_FILENAME'])."/data_db/";//test
}

/// Returns the tool test output folder
function output_folder()
{
	$folder = dirname($_SERVER['SCRIPT_FILENAME'])."/data_out/".basename($_SERVER['SCRIPT_FILENAME'], ".php")."/";
	if (!file_exists($folder)) mkdir($folder);
	return $folder;
}

/// Returns the source folder
function src_folder()
{
	return dirname($_SERVER['SCRIPT_FILENAME'])."/../src/";
}

/// Converts a variable to a string (special handing of arrays)
function readable($data)
{
	if (!is_array($data))
	{
		if (is_bool($data))
		{
			return $data? "true": "false";
		}
		
		return $data;
	}
	
	return "array(".implode(",", array_map("readable", $data)).")";
}

/// Deletes all files from the output folder
function clear_output_folder()
{
	$dir = output_folder();
	if (file_exists($dir))
	{
		exec2("rm -rf $dir/*");
	}
}

///  Starts a test
function start_test($name)
{
	clear_output_folder();
	
	//checking for debug mode (argument '-d' on command line)
	$GLOBALS["debug"] = false;
	$args = getopt("d");
	if (isset($args["d"])) $GLOBALS["debug"] = true;
	$args = getopt("v");
	if (isset($args["v"])) $GLOBALS["debug"] = true;
	
	print "TEST $name\n";
	$GLOBALS["failed"] = 0;
	$GLOBALS["passed"] = 0;
}

/// Performs a check within a test
function check($observed, $expected, $delta = null)
{
	if (isset($delta))
	{
		$delta_string = ", delta='$delta'";
		$passed = abs($observed - $expected) <= $delta;
	}
	else
	{
		$delta_string = "";
		$passed = $observed==$expected;
	}
	
	if (!$passed)
	{
		$result = "FAILED (expected '".readable($expected)."', got '".readable($observed)."'$delta_string)";
		++$GLOBALS["failed"];
	}
	else
	{
		$result = "PASSED";
		++$GLOBALS["passed"];
	}
	
	$bt = debug_backtrace();
	$caller = array_shift($bt);
	$file = basename($caller["file"]);
	$line = $caller["line"];
	print "  - $file:$line $result\n";
}

///Removes lines that contain any string from the @p ignore_strings array 
function remove_lines_containing($filename, $ignore_strings)
{
	//load input
	$file = file($filename);
	
	$h = fopen($filename, "w");
	for($i=0; $i<count($file); ++$i)
	{
		$ignore = false;
		foreach($ignore_strings as $needle)
		{
			if (contains($file[$i], $needle))
			{
				$ignore = true;
				break;
			}
		}
		if (!$ignore)
		{
			fwrite($h, $file[$i]);
		}
	}
	fclose($h);
}

/// Performs an equality check on files. Optionally, header lines starting with '#' can be compared as well.
function check_file($out_file, $reference_file, $comare_header_lines = false)
{
	$logfile = $out_file."_diff";
	
	//zdiff
	if (ends_with($out_file, ".gz") && ends_with($reference_file, ".gz"))
	{
	
		$extras = "";
		if (!$comare_header_lines) $extras .= " -I^[#@]";
		exec("zdiff $extras -b $reference_file $out_file > $logfile 2>&1", $output, $return);
		$passed = ($return==0 && count(file($logfile))==0);
	}
	//zipcmp
	elseif (ends_with($out_file, ".zip") && ends_with($reference_file, ".zip"))
	{
		exec("zipcmp $reference_file $out_file > $logfile 2>&1", $output, $return);
		$passed = ($return==0 && count(file($logfile))==0);
	}
	//bam (diff 'samtools view' format)
	elseif (ends_with($out_file, ".bam") && ends_with($reference_file, ".bam"))
	{
		$o = temp_file("_out.sam");
		exec(get_path("samtools")." view $out_file | cut -f1-11 > $o 2>&1", $output, $return); //cut to ignore the read-group and other annotations
		$r = temp_file("_ref.sam");
		exec(get_path("samtools")." view $reference_file | cut -f1-11 > $r 2>&1", $output, $return); //cut to ignore the read-group and other annotations
		
		exec("diff -b $o $r > $logfile 2>&1", $output, $return);
		$passed = ($return==0);
	}
	//diff
	else
	{
		$extras = "";
		if (!$comare_header_lines) $extras .= " -I ^[#@]";
		exec("diff $extras -b $reference_file $out_file > $logfile 2>&1", $output, $return);

		$passed = ($return==0);
	}
	
	if ($passed)
	{
		$result = "PASSED";
		++$GLOBALS["passed"];
	}
	else
	{
		$result = "FAILED (see $logfile)";
		++$GLOBALS["failed"];
	}
	
	$bt = debug_backtrace();
	$caller = array_shift($bt);
	$file = basename($caller["file"]);
	$line = $caller["line"];
	print "  - $file:$line $result\n";
}

/// Executes a command and checks that it does not return an error code
function check_exec($command, $fail = TRUE)
{	
	// execute command
	if ($GLOBALS["debug"]) print "    Executing: $command\n";
	exec($command." 2>&1", $output, $return);
	
	//check if output contains PHP warning
	$warning = false;
	foreach ($output as $line)
	{
		if (starts_with($line, "PHP") && (contains($line, "Warning") || contains($line, "Error") || contains($line, "Notice")))
		{
			$warning = true;
		}
	}
	
	if (($return!=0 || $warning) && $fail)
	{
		++$GLOBALS["failed"];
		
		//write output to logfile
		$logfile = output_folder().random_string(4)."_output";
		file_put_contents($logfile, implode("\n", $output));
		
		//issue error message
		$bt = debug_backtrace();
		$caller = array_shift($bt);
		$file = basename($caller["file"]);
		$line = $caller["line"];
		print "  - $file:$line FAILED (see $logfile)\n";
	}
	
	if ($GLOBALS["debug"])
	{
		foreach($output as $line)
		{
			print "stdout> ".$line."\n";
		}
	}
	
	return $output;
}

/// Ends a test
function end_test()
{
	if ($GLOBALS["failed"]==0)
	{
		print "PASSED (".$GLOBALS["passed"].")\n\n";
		
		if (!$GLOBALS['debug'])
		{
			clear_output_folder();
		}
	}
	else
	{
		print "FAILED (".$GLOBALS["failed"]." of ".$GLOBALS["passed"].")\n\n";
		exit(1);
	}
}

function production_ngsd_enabled()
{
	return get_db('NGSD', 'db_host')!="";
}

?>