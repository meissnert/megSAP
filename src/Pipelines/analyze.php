<?php

/**
	@page analyze
*/

$basedir = dirname($_SERVER['SCRIPT_FILENAME'])."/../";

require_once($basedir."Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

//parse command line arguments
$parser = new ToolBase("analyze", "\$Rev: 907 $", "Complete NGS analysis pipeline.");
$parser->addString("folder", "Analysis data folder.", false);
$parser->addString("name", "Base file name, typically the processed sample ID (e.g. 'GS120001_01').", false);
//optional
$parser->addInfile("system",  "Processing system INI file (determined from 'name' by default).", true);
$steps_all = array("ma", "vc", "an", "db", "cn");
$parser->addString("steps", "Comma-separated list of processing steps to perform.", true, implode(",", $steps_all));
$parser->addFlag("backup", "Backup old analysis files to old_[date] folder.");
$parser->addFlag("lofreq", "Add low frequency variant detection.", true);
$parser->addInt("threads", "The maximum number of threads used.", true, 2);
$parser->addInt("thres", "Splicing region size used for annotation (flanking the exons).", true, 20);
$parser->addFlag("clip_overlap", "Soft-clip overlapping read pairs.", true);
$parser->addFlag("no_abra", "Skip realignment with ABRA.", true);
$parser->addString("out_folder", "Folder where analysis results should be stored. Default is same as in '-folder' (e.g. Sample_xyz/).", true, "default");
extract($parser->parse($argv));

$sys = load_system($system, $name);

if($out_folder=="default")
{
	$out_folder = $folder;
}

//check steps
$steps = explode(",", $steps);
foreach($steps as $step)
{
	if (!in_array($step, $steps_all)) trigger_error("Unknown processing step '$step'!", E_USER_ERROR);
}

//log server name
list($server) = exec2("hostname -f");
list($user) = exec2("whoami");
$parser->log("Executed on server: ".implode(" ", $server)." as ".implode(" ", $user));

//set up local NGS data copy (to reduce network traffic and speed up analysis)
$parser->execTool("php ".$basedir."Tools/data_setup.php", "-build ".$sys['build']);

//output file names
//rename out folder
$bamfile = $out_folder."/".$name.".bam";
if(!in_array("ma", $steps))	$bamfile = $folder."/".$name.".bam";
$vcffile = $out_folder."/".$name."_var.vcf.gz";
if(!in_array("vc", $steps))	$vcffile = $folder."/".$name."_var.vcf.gz";
$varfile = $out_folder."/".$name.".GSvar";
$lowcov_file = $out_folder."/".$name."_".$sys["name_short"]."_lowcov.bed";
if(!in_array("an", $steps))	$varfile = $folder."/".$name.".GSvar";
$log_ma  = $out_folder."/".$name."_log1_map.log";
$log_vc  = $out_folder."/".$name."_log2_vc.log";
$log_an  = $out_folder."/".$name."_log3_anno.log";
$log_db  = $out_folder."/".$name."_log4_db.log";
$log_cn  = $out_folder."/".$name."_log5_cn.log";
$qc_fastq  = $out_folder."/".$name."_stats_fastq.qcML";
$qc_map  = $out_folder."/".$name."_stats_map.qcML";
$qc_vc  = $out_folder."/".$name."_stats_vc.qcML";
$cnvfile = $out_folder."/".$name."_cnvs.tsv";
$cnvfile2 = $out_folder."/".$name."_cnvs.seg";

//move old data to old_[date]_[random]-folder
if($backup && in_array("ma", $steps))
{
	$backup_pattern = "$name*.*,analyze*.log";
	$skip_pattern = array();
	$skip_pattern[] = "\w+\.fastq\.gz$";
	$skip_pattern[] = "SampleSheet\.csv$";
	if(!is_null($parser->getLogFile()))	$skip_pattern[] = $parser->getLogFile()."$";
	backup($out_folder, $backup_pattern, "#".implode("|", $skip_pattern)."#");
}

//mapping
if (in_array("ma", $steps))
{
	//determine input FASTQ files
	$in_for = $out_folder."/*_R1_001.fastq.gz";
	$in_rev = $out_folder."/*_R2_001.fastq.gz";
	
	//find FastQ input files
	$files1 = glob($in_for);
	$files2 = glob($in_rev);
	if (count($files1)!=count($files2))	trigger_error("Found mismatching forward and reverse read file count!\n Forward: ".implode(" ", $in_for)."\n Reverse: ".implode(" ", $in_rev), E_USER_ERROR);

	$extras = array();
	if($clip_overlap) $extras[] = "-clip_overlap";
	if($no_abra) $extras[] = "-no_abra";
	if(file_exists($log_ma)) unlink($log_ma);
	
	$parser->execTool("php ".$basedir."Pipelines/mapping.php", "-in_for ".implode(" ", $files1)." -in_rev ".implode(" ", $files2)." -system $system -out_folder $out_folder -out_name $name --log $log_ma ".implode(" ", $extras)." -threads $threads");
}

//variant calling
if (in_array("vc", $steps))
{	
	$extras = array();
	if ($sys['target_file']!="") $extras[] = " -target ".$sys['target_file'];
	if ($lofreq) //lofreq
	{
		$extras[] = " -min_af 0.05";
	}
	else if (!$sys['shotgun']) //amplicon panels
	{
		$extras[] = " -min_af 0.1";
	}
	
	if(file_exists($log_vc)) unlink($log_vc);
	$parser->execTool("php ".$basedir."NGS/vc_freebayes.php", "-bam $bamfile -out $vcffile -build ".$sys['build']." --log $log_vc ".implode(" ", $extras));
}

//annotation and reports
if (in_array("an", $steps))
{
	if(file_exists($log_an)) unlink($log_an);
	$parser->execTool("php ".$basedir."Pipelines/annotate.php", "-out_name $name -out_folder $out_folder -system $system -thres $thres --log $log_an");
	
	//low-coverage report
	if($sys['type']=="WGS") //WGS
	{
		$parser->exec(get_path("ngs-bits")."BedLowCoverage", "-wgs -bam $bamfile -out $lowcov_file -cutoff 20", false);
		$parser->exec(get_path("ngs-bits")."BedAnnotateGenes", "-in $lowcov_file -extend 25 -out $lowcov_file", true);
	}
	else if ($sys['target_file']!="") //ROI (but not WGS)
	{	
		$parser->exec(get_path("ngs-bits")."BedLowCoverage", "-in ".$sys['target_file']." -bam $bamfile -out $lowcov_file -cutoff 20", false);
		$parser->exec(get_path("ngs-bits")."BedAnnotateGenes", "-in $lowcov_file -extend 25 -out $lowcov_file", true);
	}

	//x-diagnostics report
	if ($sys['name_short']=="ssX" || starts_with($sys['name_short'], "hpXLIDv"))
	{
		$parser->execTool("php ".$basedir."Pipelines/x_diagnostics.php", "-bam $bamfile -out_folder {$out_folder} --log $log_an -system {$system}");
	}
}

//import to database
if (in_array("db", $steps))
{
	if(file_exists($log_db)) unlink($log_db);
	$parser->execTool("php ".$basedir."NGS/db_check_gender.php", "-in $bamfile -pid $name --log $log_db");
	
	//import variants
	if (file_exists($varfile))
	{
		$parser->execTool("php ".$basedir."NGS/db_import_variants.php", "-id $name -var $varfile -build ".$sys['build']." -force --log $log_db");
	}
	
	//update last_analysis column of processed sample in NGSD (before db_import_qc.php because that can throw an error because of low coverage)
	updateLastAnalysisDate($name, $bamfile);

	//import QC data
	$qc_files = array($qc_fastq, $qc_map);
	if (file_exists($qc_vc)) $qc_files[] = $qc_vc; 
	$parser->execTool("php ".$basedir."NGS/db_import_qc.php", "-id $name -files ".implode(" ", $qc_files)." -force --log $log_db");
}

//TODO special-handling of WGS data! How?
//copy-number analysis
if (in_array("cn", $steps))
{
	if(file_exists($log_cn)) unlink($log_cn);
	
	//create coverage file
	$tmp_folder = $parser->tempFolder();
	$cov_file = $tmp_folder."/{$name}.cov";
	$parser->exec(get_path("ngs-bits")."BedCoverage", "-min_mapq 0 -bam $bamfile -in ".$sys['target_file']." -out $cov_file", true);

	//copy coverage file to reference folder (has to be done before CnvHunter call to avoid analyzing the same sample twice)
	if (is_valid_ref_sample_for_cnv_analysis($name))
	{
		//create reference folder if it does not exist
		$ref_folder = get_path("data_folder")."/coverage/".$sys['name_short']."/";
		if (!is_dir($ref_folder)) mkdir($ref_folder);
		
		//copy file
		$ref_file = $ref_folder.$name.".cov";
		copy2($cov_file, $ref_file);
		$cov_file = $ref_file;
	}
	
	//perform copy-number analysis
	$cnv_out = $tmp_folder."/output.tsv";
	$cnv_out2 = $tmp_folder."/output.seg";
	$parser->execTool("php $basedir/NGS/vc_cnvhunter.php", "-cov $cov_file -system $system -out $cnv_out -seg $name --log $log_cn");

	//copy results to output folder
	if (file_exists($cnv_out)) copy2($cnv_out, $cnvfile);
	if (file_exists($cnv_out2)) copy2($cnv_out2, $cnvfile2);
}
