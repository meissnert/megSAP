<?php

require_once("functions.php");

/**
	@brief Base class for tools.
	
	This class support common tool tasks, i.e.:
	- Command line parsing
	- Logging
	- Execution of external tools
	- Error handling
	- Temporary file creation and cleanup
	
	@ingroup base
*/
class ToolBase
{
	private $name = "";
	private $version = "";
	private $description = "";
	private $params = array();
	
	private $log_file = null;
	private $log_id = "";
	private $start_time = null;
	private $verbose = false;
	
	private $temp_files = array();
	private $temp_folders = array();
	private $error_occurred = false;
	private $queued_jobs = array();
	
	/// Constructor
	function __construct($name, $version, $description)
	{
        $this->name = $name;
        $this->description = $description;
		
		// check name
		$filename = basename($_SERVER['SCRIPT_FILENAME']);
		if ($filename != $name.".php")
		{
			print "ERROR: Tool name '$name' and file name '$filename' do not match.\n";
			exit(1);
		}
		
		// Special handling of SVN revision
		if (substr($version, 0, 5)=="\$Rev:")
		{
			$version = trim(substr($version, 5, -1));
		}
        $this->version = $version;
		
		// Set start time
		$this->start_time = microtime(true);
		
		// Create random log_id. Overwritten if log_id command line flag is used
		$this->log_id = random_string(4);
		
		// register callbacks
		register_shutdown_function(array($this, 'shutdown'));
		set_error_handler(array($this, 'error_handler'));
		pcntl_signal(SIGINT, array($this, 'shutdownSIG'));
	}
	
	/// Shutdown function (destructor cannot be used, because it is not called after 'trigger_error' is used)
	function shutdown()
	{
		//remove all temp files
		if (!$this->error_occurred)
		{
			foreach($this->temp_files as $file)
			{
				if (!file_exists($file))
				{
					trigger_error("Temporary file '$file' does not exist.", E_USER_NOTICE);
				}
				
				if (!unlink($file))
				{
					trigger_error("Temporary file '$file' could not be deleted.", E_USER_NOTICE);
				}
			}
			
			foreach($this->temp_folders as $folder)
			{
			    if(!is_dir($folder))
			    {
					trigger_error("Temporary folder '$folder' does not exist.", E_USER_NOTICE);
			    }
				
				$this->removeTempFolder($folder);
			}
		}
		
		if(!empty($this->queued_jobs))
		{
			$deleted_jobs = array();
			$undeleted_jobs = array();
			foreach($this->queued_jobs as $qj)
			{
				$qdel_message = jobDelete($qj);
				if(strpos($qdel_message, "denied:")!==FALSE)	$undeleted_jobs[] = $qj;
				else	$deleted_jobs[] = $qj;
			}
			if(!empty($deleted_jobs))	trigger_error("Deleted following child processes: ".implode(", ",$deleted_jobs),E_USER_NOTICE);
			if(!empty($undeleted_jobs))	trigger_error("Could not delete the following child processes: ".implode(", ",$undeleted_jobs),E_USER_NOTICE);
		}
		
		//final log message
		$this->log("END ".$this->name);
		$this->log("Execution time of '".$this->name."': ".time_readable(microtime(true) - $this->start_time));
	}

	/// Shutdown function for ctl-c, delete all jobs depending on this object, keep tmp files (no shutdown)
	function shutdownSIG($sig)
	{
		trigger_error("Aborted by user; shutdown.", E_USER_NOTICE);
		die;	//runs shutdown function to clean up files / folders / queued jobs
	}
	
	/// Remove temporary folder
	private function removeTempFolder($folder)
	{
		//only delete files within tmp folder
		$tmp = sys_get_temp_dir();
		if(!starts_with($folder, $tmp)) trigger_error("Folder '$folder' not within tmp-folder '$tmp'.", E_USER_ERROR);
		if (substr($folder, strlen($folder) - 1, 1)!='/') $folder .= '/';
		
		//get all files / sub_folders
		$files = scandir($folder);

		foreach ($files as $file) 
		{
		//exclude '.' and '..'
		if($file == "." || $file == "..") continue;
		
		if (is_dir($folder.$file)) 
		{
			//delete recursively
			$this->removeTempFolder($folder.$file);
		} 
		else 
		{
			//delete file
			unlink($folder.$file);
		}
		}
		
		//delete folder
		rmdir($folder);
	}
	
	/// Handles errors
	function error_handler($level, $message, $file, $line, $context)
	{
		$type = "";
		//log error
		if($level & E_USER_ERROR)
		{
			$this->error_occurred = true;
			$type = "ERROR";
		}
		//log warning
		if($level & E_USER_WARNING)
		{
			$type = "WARNING";
		}
		//log error
		if($level & E_USER_NOTICE)
		{
			$type = "NOTICE";
		}
		
		//log and print known types
		if ($type!="")
		{
			if ($this->verbose)
			{
				$tb = traceback();
				$this->log("$type: '$message' in $file:$line.", array($tb));
				$this->toStdErr("$type: '$message' in $file:$line.\n".$tb);
			}
			else
			{
				$this->log("$type: '$message' in $file:$line.");
				$this->toStdErr("$type: '$message' in $file:$line.");
			}
		}
		
		return false; //use PHP's error handler afterwards
	}

	/// @name Parameter handling members.
	//@{
	
	/// Adds a flag. It not used, the value parameter is set to 'false' (do not used isset!).
	function addFlag($name, $desc)
	{
		$this->params["-".$name] = array("flag", $desc, null, null, null, null);
	}
	
	/// Adds an input file parameter
	function addInfile($name, $desc, $opt, $check_readable = true)
	{
		$this->add($name, $desc, $opt, null, "infile", $check_readable);
	}

	/// Adds an input file array parameter
	function addInfileArray($name, $desc, $opt, $check_readable = true)
	{
		$this->add($name, $desc, $opt, null, "infile_array", $check_readable);
	}
		
	/// Adds an output file parameter. The variable is unset if optional and not given!
	function addOutfile($name, $desc, $opt)
	{
		$this->add($name, $desc, $opt, null, "outfile");
	}

	/// Adds an output file array parameter.
	function addOutfileArray($name, $desc, $opt)
	{
		$this->add($name, $desc, $opt, null, "outfile_array");
	}
	
	/// Adds an enum parameter
	function addEnum($name, $desc, $opt, $valid, $default = null)
	{
		if (isset($default) && !in_array($default, $valid))
		{
			trigger_error("Default value '$default' of enum '$name' not contained in valid values ".implode(",", $valid).".", E_USER_ERROR);
		}
		
		$this->add($name, $desc, $opt, $default, "enum", $valid);
	}

	/// Adds an string parameter
	function addString($name, $desc, $opt, $default = null)
	{
		$this->add($name, $desc, $opt, $default, "string");
	}

	/// Adds an string parameter
	function addStringArray($name, $desc, $opt, $default = null)
	{
		$this->add($name, $desc, $opt, $default, "string_array");
	}
	
	/// Adds an int parameter
	function addInt($name, $desc, $opt, $default = null)
	{
		$this->add($name, $desc, $opt, $default, "int");
	}

	/// Adds a float parameter
	function addFloat($name, $desc, $opt, $default = null)
	{
		$this->add($name, $desc, $opt, $default, "float");
	}

	/// Adds a parameter (internal)
	private function add($name, $desc, $opt, $default, $type, $option1 = null, $option2 = null)
	{
		//check for duplicate name
		if(in_array("-".$name, array_keys($this->params)))
		{
			trigger_error("Parameter name '-$name' given twice.", E_USER_ERROR);
		}
		
		if ($opt)
		{
			$this->params["-".$name] = array("opt", $desc, $type, $option1, $option2, $default);
		}
		else
		{
			if (isset($default))
			{
				trigger_error("Default value '$default' for non-optional parameter '$name' given.", E_USER_ERROR);
			}
			
			$this->params["-".$name] = array("par", $desc, $type, $option1, $option2, null);
		}
	}
	
	/// Main parsing function
	/// @note Use the PHP function extract() to import the result to the calling namespace
	function parse($argv)
	{	
		// Special paramters
		if (in_array("--help", $argv))
		{
			$this->printUsage();
			exit(0);
		}
		if(in_array("--version", $argv))
		{
			print "$this->name version $this->version\n";
			exit(0);
		}
		if(in_array("--verbose", $argv))
		{
			$this->verbose = true;
			$arg_index = array_search("--verbose", $argv);
			array_splice($argv, $arg_index, 1, array());	
		}
		if(in_array("--log", $argv))
		{
			$arg_index = array_search("--log", $argv);
			$this->log_file = $argv[$arg_index+1];
			array_splice($argv, $arg_index, 2, array());	
		}
		if(in_array("--log_id", $argv))
		{
			$arg_index = array_search("--log_id", $argv);
			$this->log_id = $argv[$arg_index+1];
			array_splice($argv, $arg_index, 2, array());	
		}
		if(in_array("--conf", $argv))
		{
			$arg_index = array_search("--conf", $argv);
			$GLOBALS["path_ini"] = $argv[$arg_index+1];
			array_splice($argv, $arg_index, 2, array());	
		}
		if(in_array("--tdx", $argv))
		{
			$filename = $_SERVER['PHP_SELF'].".tdx";
			$this->storeTDX($filename);
			exit(0);
		}
		
		//log tool start
		$this->log("START ".$this->name." (version: ".$this->version.")");
		$this->log("Verbose: ".($this->verbose ? "true" : "false"));
		
		//set default values
		$output = array();
		foreach($this->params as $name => $details)
		{
			if ($details[0] == "flag")
			{
				$output[substr($name,1)] = false;
			}
			else if ($details[0] == "opt" && isset($details[5]))
			{
				$output[substr($name,1)] = $details[5];
			}
		}
		
		//parse command line
		$para_present = array();
		for ($i=1; $i<count($argv); ++$i)
		{
			$par = $argv[$i];
			if ($par[0] != "-")
			{
				$this->printUsage();
				trigger_error("Trailing argument '$par' given.", E_USER_ERROR);
			}
			
			
			if (!in_array($par, array_keys($this->params)))
			{				
				$this->printUsage();
				trigger_error("Unknown parameter '$par' given.", E_USER_ERROR);
			}
			
			// handle parameters with arguments
			$para_type = $this->params[$par][0];
			if ($para_type=="par" || $para_type=="opt")
			{
				// get argument
				if (!isset($argv[$i + 1]) || $argv[$i + 1][0]=="-")
				{
					$this->printUsage();
					trigger_error("Parameter '$par' given without argument.", E_USER_ERROR);
				}
				$arg = $argv[$i + 1];
				
				if (in_array($par, $para_present))
				{
					$this->printUsage();
					trigger_error("Parameter '$par' given more than once.", E_USER_ERROR);
				}
				$para_present[] = $par;
				
				// check argument data types
				$argu_type = $this->params[$par][2];
				$argu_rest = $this->params[$par][3];
				if ($argu_type == "infile")
				{
					$check_readable = $this->params[$par][3];
					if ($check_readable && !is_readable($arg))
					{
						$this->printUsage();
						trigger_error("Input file '$arg' given for parameter '$par' is not readable.", E_USER_ERROR);
					}
				}
				else if($argu_type == "infile_array")
				{
					$arg = array();
					while(isset($argv[$i + 1]) &&  $argv[$i + 1][0]!="-")
					{
						//print $argv[$i+1]."\n";
						$arg[] = $argv[$i+1];
						$check_readable = $this->params[$par][3];
						if ($check_readable && !is_readable(end($arg)))
						{
							$this->printUsage();
							trigger_error("Input file '".end($arg)."' given for list parameter '$par' is not readable.", E_USER_ERROR);
						}
						++$i;
					}
					--$i;
				}
				else if ($argu_type == "outfile")
				{
					if (!is_writable2($arg))
					{
						$this->printUsage();
						trigger_error("Output file '$arg' given for parameter '$par' is not writable.", E_USER_ERROR);
					}
				}
				else if($argu_type == "outfile_array")
				{
					$arg = array();
					while(isset($argv[$i + 1]) &&  $argv[$i + 1][0]!="-")
					{
						$arg[] = $argv[$i+1];
						if (!is_writable2(end($arg)))
						{
							$this->printUsage();
							trigger_error("Output file '".$argv[$i+1]."' given for list parameter '$par' is not writable.", E_USER_ERROR);
						}
						++$i;
					}
					--$i;
				}
				else if ($argu_type == "enum")
				{
					if (!in_array($arg, $argu_rest))
					{
						$this->printUsage();
						trigger_error("Invalid value '$arg' given for restriced parameter '$par'.", E_USER_ERROR);
					}
				}
				else if ($argu_type == "string")
				{
					// nothing to check
				}
				else if ($argu_type == "string_array")
				{
					$arg = array();
					
					while(isset($argv[$i + 1]) && $argv[$i + 1][0]!="-")
					{
						$arg[] = $argv[$i+1];
						++$i;
					}
					--$i;
				}
				else if ($argu_type == "int")
				{
					if ($arg != (string)(int)$arg)
					{
						$this->printUsage();
						trigger_error("Invalid value '$arg' given for integer parameter '$par'.", E_USER_ERROR);
					}
				}
				else if ($argu_type == "float")
				{
					if ($arg != (string)(float)$arg)
					{
						$this->printUsage();
						trigger_error("Invalid value '$arg' given for float parameter '$par'.", E_USER_ERROR);
					}
				}
				
				$output[substr($par,1)] = $arg;
				
				++$i;
			}
			// handle flags
			else if ($para_type=="flag")
			{
				$output[substr($par,1)] = true;
			}
		}
		
		// check missing parameters
		foreach($this->params as $name => $details)
		{
			if ($details[0] == "par" && !in_array($name, $para_present))
			{
				$this->printUsage();
				trigger_error("Mandatory parameter '$name' not given.", E_USER_ERROR);
			}
		}
		
		//log parameters
		if (count($output)!=0)
		{
			$length = max(array_map('strlen', array_keys($output)));
			foreach($output as $name => $value)
			{
				if(is_array($value))
				{
					$value = implode(" ", $value);
				}
				$this->log("Parameter: ".str_pad($name, $length, " ")." = $value");
			}
		}
		
		return $output;
	}
	
	/// Prints the usage information
	function printUsage()
	{
		// find out longest parameter and agrument name
		$max_name = 0;
		$max_arg = 0;
		foreach($this->params as $name => $details)
		{
			$max_name = max($max_name, strlen($name));
			$max_arg = max($max_arg, strlen($details[2]));
		}
		$offset = max(18, $max_name + $max_arg + 6);
		$indent = "  ".str_pad("", $offset, " ");
		
		print $this->name." (version ".$this->version.")\n";
		print "\n";
		print $this->description."\n";
		
		// Mandatory parameters
		$mandatory = array();
		foreach($this->params as $name => $details)
		{
			if ($details[0]=="par")
			{
				$mandatory[] = "  ".str_pad($name." <".$details[2].">", $offset, " ").$details[1]."\n";
				
				if ($details[2]=="enum")
				{
					$mandatory[] = $indent."Valid are: '".implode("', '", $details[3])."'.\n";
				}
			}
		}
		if (count($mandatory))
		{
			print "\n";
			print "Mandatory parameters:\n";
			print implode("", $mandatory);
		}

		// Optional parameters
		$optional = array();
		foreach($this->params as $name => $details)
		{
			if ($details[0]=="opt")
			{
				$optional[] = "  ".str_pad($name." <".$details[2].">", $offset, " ").$details[1]."\n";
				
				if ($details[2]=="enum")
				{
					$optional[] = $indent."Valid are: '".implode("', '", $details[3])."'.\n";
				}
				
				if (isset($details[5]))
				{
					$optional[] = $indent."Default is: '".$details[5]."'.\n";
				}
				
			}
			else if ($details[0] == "flag")
			{
				$optional[] = "  ".str_pad($name, $offset, " ").$details[1]."\n";
			}
		}
		if (count($optional))
		{
			print "\n";
			print "Optional parameters:\n";
			print implode("", $optional);
		}

		print "\n";
		print "Special parameters:\n";
		print "  ".str_pad("--help", $offset, " ")."Shows this help.\n";
		print "  ".str_pad("--version", $offset, " ")."Prints version and exits.\n";
		print "  ".str_pad("--verbose", $offset, " ")."Verbose error messages including traceback.\n";
		print "  ".str_pad("--log <file>", $offset, " ")."Enables logging to the specified file.\n";
		print "  ".str_pad("--log_id <id>", $offset, " ")."Uses the given identifier for logging.\n";
		print "  ".str_pad("--conf <file>", $offset, " ")."Uses the given configuration file.\n";
		print "  ".str_pad("--tdx", $offset, " ")."Writes a Tool Defition XML file.\n";
		
		print "\n";
		print "\n";
	}

	//@}
	
	
	/// @name Logging members
	//@{
	
	/// Logs a message and optional additional info.
	public function log($message, $add_info = array())
	{
		if (isset($this->log_file))	//print to log file
		{
			$prefix = get_timestamp()."\t".$this->log_id."\t";
			
			$lines = array();
			$lines[] = $prefix."$message\n";
			foreach($add_info as $line)
			{
				if (contains($line, "WARNING(freebayes): Could not find any mapped reads in target region")) continue; //excessive freebayes output

				$lines[] = $prefix."    ".strtr($line, array("\n" => "", "\c" => "", "\t" => "  "))."\n";
			}
			
			$result = file_put_contents($this->log_file, $lines, LOCK_EX | FILE_APPEND);
			if ($result===FALSE)
			{
				trigger_error("Could not write to ".$this->log_file."!", E_USER_ERROR);
			}
		}
		if($this->verbose)
		{
			print $message."\n";
		}
	}
	
	/// Prints a message (string or array of strings) to the stderr stream
	function toStderr($message)
	{
		if (!is_array($message))
		{
			$message = array($message);
		}
		
		$handle = fopen('php://stderr', 'w');
		foreach($message as $line)
		{
			fwrite($handle,nl_trim($line)."\n");
		}
		fclose($handle);
	}
	
	/// Prints/logs a warning message.
	public function warning($message, $info = array())
	{
		$message = "WARNING: ".$message;

		//print infos
		print $message."\n";
		
		//log error
		$this->log($message, $info);
	}

	//@}
	
	//tries to extract the version number from a command
	function extractVersion($command)
	{
		$version = "n/a";
		
		//tools with --version argument
		$output = array();
		exec($command." --version 2>&1", $output, $return);
		if ($return==0 && preg_match("/[0-9]+\.[0-9\.]+[0-9A-Za-z_-]*$/", $output[0], $hits))
		{
				$version = $hits[0];
		}
		
		//tools with -version argument
		if ($version=="n/a")
		{
			$output = array();
			exec($command." -version 2>&1", $output, $return);
			if ($return==0 && preg_match("/[0-9]+\.[0-9\.]+[0-9A-Za-z_-]*$/", $output[0], $hits))
			{
				$version = $hits[0];
			}
		}
		
		return $version;
	}
	
	/**
		@brief Executes the command and returns an array with STDOUT and STDERR.
		
		If the call exits with an error code, further execution of the calling script is aborted.
	*/
	function exec($command, $parameters, $log_output)
	{
		//log call
		if($log_output)
		{
			$add_info = array();
			$add_info[] = "version    = ".$this->extractVersion($command);
			$add_info[] = "parameters = $parameters";
			$this->log("Calling external tool '$command'", $add_info);
		}
		
		//execute call and pipe stderr stream to file
		$exec_start = microtime(true);
		$temp = $this->tempFile(".stderr");
		exec("$command $parameters 2>$temp", $stdout, $return);
		$stderr = file($temp);
			
		//log relevant information
		if (($log_output || $return != 0) && count($stdout)>0)
		{
			$this->log("Stdout of '$command':", $stdout);
		}
		if (($log_output || $return != 0) && count($stderr)>0)
		{
			$this->log("Stderr of '$command':", $stderr);
		}
		if ($log_output)
		{
			$this->log("Execution time of '$command': ".time_readable(microtime(true) - $exec_start));
		}
		
		//abort on error
		if ($return != 0)
		{	
			$this->toStderr($stderr);
			trigger_error("Call of external tool '$command' returned error code '$return'.", E_USER_ERROR);
		}
		
		//return results
		return array($stdout, $stderr);
	}

	/**
		@brief Executes a pipeline (array of commands and parameters) and returns an array with STDOUT and STDERR.
		
		If the call exits with an error code, further execution of the calling script is aborted.
		If no version number is given, an automatic extraction of the version number is tried.
	*/
	function execPipeline($commands_params, $name, $log_output=true)
	{
		$add_info = array();
		$parts = array();
		$stderr_files = array();
		foreach($commands_params as $call)
		{
			list($command, $params) = $call;
			
			$stderr_file = $this->tempFile(".stderr");
			$parts[] = "$command $params 2>$stderr_file";
			$stderr_files[] = $stderr_file;
			$add_info[] = "command ".count($parts)."  = $command";
			if (!isset($call[2]) || $call[2]==true)
			{
				$add_info[] = "version    = ".$this->extractVersion($command);
			}
			$add_info[] = "parameters = $params";	
			
		}
			
		//log call
		$pipeline = implode(" | ", $parts);		
		$add_info[] = "pipeline   = $pipeline";			
		if($log_output)
		{
			$this->log("Calling '$name' pipeline", $add_info);
		}
			
		$exec_start = microtime(true);
		exec("bash -c \"set -o pipefail && ".strtr($pipeline, array("\""=>"\\\""))."\"", $stdout, $return); //pipefail is needed because otherwise only the exit code of the last tool is returned!
		
		//log stdout
		if (($log_output || $return!=0) && count($stdout)>0)
		{
			$this->log("Stdout of '$name' pipeline:", $stdout);
		}
		//log stderr
		if($log_output || $return!=0)
		{
			$stderr = array();
			foreach($stderr_files as $stderr_file)
			{
				$stderr += file($stderr_file);
			}
			if (count($stderr)>0)
			{
				$this->log("Stderr of '$name' pipeline:", $stderr);
			}
		}
		//log execution time
		if ($log_output)
		{
			$this->log("Execution time of '$name' pipeline: ".time_readable(microtime(true) - $exec_start));
		}
		
		//abort on error
		foreach($stderr as $line)
		{
			if ($return!=0) break;
		}
		if ($return!=0)
		{	
			$this->toStderr($stderr);
			trigger_error("Call of '$name' pipeline returned error code '$return'.", E_USER_ERROR);
		}
		
		//return results
		return array($stdout, $stderr);
	}
	
	/// Returns the version of this tool
	function version()
	{
		return $this->version;
	}
	
	/**
		@brief Executes a other tool which is based on ToolBase and returns an array with the output to STDOUT and STDERR.
		
		The output of the tool to stdout and stderr is logged. The version number is automatically determined and logged.
		
		If the call exits with an error code, further execution of the calling script is aborted.
	*/
	function execTool($command, $parameters)
	{
		//determine tool version
		$output = array();
		exec($command." --version", $output, $return);
		preg_match("/[\d\.]+$/", $output[0], $hits);
		$version = $hits[0];
		
		//create call id
		$call_id = random_string(4);

		//log call
		$add_info = array();
		$add_info[] = "version    = $version";
		$add_info[] = "parameters = $parameters";
		$add_info[] = "log_id     = $call_id";
		$this->log("Calling external tool '$command'", $add_info);
		
		//execute call and pipe stderr stream to file
		$temp = $this->tempFile(".stderr");
		$addinfo = "";
		if (isset($this->log_file) && !contains($parameters, "--log"))
		{
			$addinfo .= "--log $this->log_file --log_id $call_id ";
		}
		if (isset($GLOBALS["path_ini"]) && !contains($parameters, "--conf"))
		{
			$addinfo .= "--conf ".$GLOBALS["path_ini"]." ";
		}
		if ($this->verbose && !contains($parameters, "--verbose"))
		{
			$addinfo .= "--verbose ";
		}
		
		exec($command." ".$parameters." $addinfo 2>$temp", $stdout, $return);
		//print($command." ".$parameters." $addinfo 2>$temp");
		$stderr = file($temp);
		
		//log output streams
		if (count($stdout)>0)
		{
			$this->log("Stdout of '$command':", $stdout);
		}
		if (count($stderr)>0)
		{
			$this->log("Stderr of '$command':", $stderr);
		}
		
		//abort on error
		if ($return != 0)
		{	
			$this->toStderr($stderr);
			trigger_error("Call of external tool '$command' returned error code '$return'.", E_USER_ERROR);
		}
		
		//return results
		return array($stdout, $stderr);
	}
	
	/**
		@brief Returns a temporary file name
		
		@param suffix File suffix to append.
		@param prefix File prefix to prepend (default is the tool name).
		
		@note The file is deleted in the destructor of this class.
	*/
	function tempFile($suffix = "", $prefix = null)
	{
		if (!isset($prefix))
		{
			$prefix = $this->name."_";
		}
		$file = temp_file($suffix, $prefix);
		$this->temp_files[] = $file;
		
		return $file;
	}
	
	/**
		@brief Returns a temporary folder name
		
		@param prefix File prefix to prepend (default is the tool name).
		
		@note The folder is deleted in the destructor of this class.
	*/
	function tempFolder($prefix = null)
	{
		if (!isset($prefix))
		{
			$prefix = $this->name."_";
		}
		$folder = temp_folder($prefix);
		$this->temp_folders[] = $folder;
		
		return $folder;
	}	
	
	/**
		@brief Adds a file to temporary-files.
		
		@note The file is deleted in the destructor of this class.
	*/
	function addTempFile($file)
	{	
		if(!file_exists($file))
		{
			trigger_error("Temporary file '$file' does not exist!", E_USER_ERROR);
		}

		$this->temp_files[] = $file;
	}
	

	/**
		@brief Immediately deletes a temporary file, instead of deleting it in the destructor
	*/
	function deleteTempFile($file)
	{	
		if(!in_array($file, $this->temp_files))
		{
			trigger_error("Temporary file '$file' for which deletion was requested is unknown!", E_USER_ERROR);
		}
		
		if (!unlink($file))
		{
			trigger_error("Temporary file '$file' could not be deleted.", E_USER_NOTICE);
		}
		
		//remove temp file from list
		$new_temp_files = array();
		foreach($this->temp_files as $temp)
		{
			if ($temp==$file) continue;
			$new_temp_files[] = $temp;
		}
		$this->temp_files = $new_temp_files;
	}
	
	/**
		@brief Returns the log-file
		
	*/
	function getLogFile()
	{	
		return $this->log_file;
	}
	
	/**
		@brief Sets the log-file
		
	*/
	function setLogFile($file)
	{	
		$this->log_file = $file;
	}
	
	/**
		@brief Stores a Tool Definition Xml file.
	*/
	function storeTDX($filename)
	{
		$writer = new XMLConstructor();
		
		$writer->openTag("TDX", array("version"=>"1"));
		$writer->openTag("Tool", array("name"=>$this->name, "version"=>$this->version, "interpreter"=>"php"));
		$writer->addTag("Description", array(), $this->description);
		
		foreach($this->params as $name => $details)
		{
			list($opt, $desc, $type, $add1, $add2, $add3) = $details;
			
			//determine tag name
			$tag = ucfirst(strtolower($type));
			$tag = strtr($tag, array("_array"=>"List"));
			if ($opt=="flag") $tag="Flag";
			
			//write output
			$writer->openTag($tag, array("name"=>substr($name, 1)));
			$writer->addTag("Description", array(), $desc);
			if ($opt=="opt")
			{
				$attr = array();
				if ($add3!=null && ($type=="string" || $type=="int" || $type=="float" || $type=="enum"))
				{
					$attr["defaultValue"] = $add3;
				}
				$writer->addTag("Optional", $attr);
				
			}
			if ($type=="enum")
			{
				foreach($add1 as $value)
				{
					$writer->addTag("Value", array(), $value);
				}
			}
			$writer->closeTag($tag);
		}
		$writer->closeTag("Tool");
		$writer->closeTag("TDX");

		file_put_contents($filename, $writer->output());
	}
	
	function jobsSubmit($commands, $working_directory, $queue, $wait=false)
	{		
		$this->log("Submitting commands to queue '$queue':", $commands);
		
		foreach($commands as $command)
		{
			$sample_status = get_path("sample_status_folder")."/data/";
			$this->queued_jobs[] = jobSubmit($command, $working_directory, $queue, $sample_status);
			trigger_error("Submitted job '$command' to queue '$queue' (SGE). Job-ID is ".end($this->queued_jobs).".",E_USER_NOTICE);
		}
		
		if($wait)
		{
			//wait for jobs until finished and collect qstat messages
			trigger_error("Waiting for job(s) ".implode(", ", $this->queued_jobs)." to be finished.",E_USER_NOTICE);
			$q_stats = jobsWait($this->queued_jobs);

			//check end and exit status of finished jobs
			$finished_jobs = array();
			$error_jobs = array();
			foreach($q_stats as $j => $s)
			{
				if($s == "queue error")	jobDelete($j);	//cleanup SGE queue				
				if($s == "finished")	$finished_jobs[] = "$j";
				else	$error_jobs[] = "$j ($s)";
			}
			
			trigger_error("Jobs finished: ".(empty($finished_jobs)?"none":implode(", ", $finished_jobs)).".",E_USER_NOTICE);
			trigger_error("Jobs unfinished: ".(empty($error_jobs)?"none":implode(", ", $error_jobs)).".",E_USER_NOTICE);
			if(count($error_jobs)>0)	trigger_error("Not all jobs were finished properly! S. $sample_status/php.e[jobid] for details.", E_USER_ERROR);	//jobs finished in error mode
			if(empty($finished_jobs) && empty($error_jobs))	trigger_error("None of the jobs was finished at all!", E_USER_ERROR);	//jobs finished in error mode
		}
		
		$return = $this->queued_jobs;
		$this->queued_jobs = array();
		return $return;
	}
}

?>