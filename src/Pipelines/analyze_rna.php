<?php

/**
	@page analyze_rna

	@todo Add functionality to analyze non-human samples (through processing system INI file)
	@todo check with Stephan from QBIC:
			- indel realignment really necessary for RNA?
			- how should _hap chromosomes be handled?
*/

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

// parse command line arguments
$parser = new ToolBase("analyze_rna", "RNA mapping pipeline using STAR.");
$parser->addInfileArray("in_for", "Forward reads in fastq.gz file(s).", false);
$parser->addString("out_folder", "Output folder.", false);
$parser->addString("out_name", "Output base file name, typically the processed sample ID (e.g. 'GS120001_01').", false, NULL);
//optional
$parser->addInfileArray("in_rev", "Reverse reads fastq.gz file(s) for paired end alignment.", true);
$parser->addInfile("system", "Processing system INI file (Determined from 'out_name' by default).", true);
$steps_all = array("ma", "rc", "an", "fu", "db");
$parser->addString("steps", "Comma-separated list of processing steps to perform.", true, implode(",", $steps_all));
$parser->addInt("threads", "The maximum number of threads used.", true, 4);
$parser->addString("genome", "STAR genome directory, by default genome is determined from system/build.", true, "");
$parser->addString("gtfFile", "GTF file containing feature annotations (for read counting).", true, get_path("data_folder")."/dbs/UCSC/refGene.gtf");
$parser->addString("featureType", "Feature type used for mapping reads to features (for read counting).", true, "exon");
$parser->addString("gtfAttribute", "GTF attribute used as feature ID (for read counting).", true, "gene_id");
$parser->addFlag("abra", "Perform indel realignment with ABRA. By default this is skipped.");
$parser->addFlag("stranded", "Specify whether a stranded protocol was used during library preparation. Default is non-stranded.");
$parser->addFlag("dedup", "Mark duplicates after alignment.");
$parser->addFlag("sharedMemory", "Use shared memory for running STAR alignment jobs.");
$downstream_all = array("splicing","chimeric");
$parser->addString("downstream", "Keep files for downstream analysis (splicing, chimeric).", true, "");

extract($parser->parse($argv));

//check steps
$steps = explode(",", $steps);
foreach($steps as $step)
{
	if (!in_array($step, $steps_all))
	{
		trigger_error("Unknown processing step '$step'!", E_USER_ERROR);
	}
}

//init
$prefix = $out_folder."/".$out_name;
$sys = load_system($system, $out_name);
$build = $sys['build'];
$target_file = $sys['target_file'];
$paired = isset($in_rev);

//mapping and QC
$final_bam = $prefix.".bam";
$qc_fastq = $prefix."_stats_fastq.qcML";
$qc_map = $prefix."_stats_map.qcML";
if(in_array("ma", $steps))
{
	//check FASTQ quality encoding
	$files = $paired ? array_merge($in_for, $in_rev) : $in_for;
	foreach($files as $file)
	{
		list($stdout, $stderr) = $parser->exec(get_path("ngs-bits")."FastqFormat", "-in $file", true);
		if (!contains($stdout[2], "Sanger"))
		{
			trigger_error("Input file '$file' is not in Sanger/Illumina 1.8 format!", E_USER_ERROR);
		}
	}

	//check that adapters are specified
	if ($sys["adapter1_p5"]=="" || $sys["adapter2_p7"]=="")
	{
		trigger_error("No forward and/or reverse adapter sequence given!\nForward: ".$sys["adapter1_p5"]."\nReverse: ".$sys["adapter2_p7"], E_USER_ERROR);
	}

	//adapter trimming + QC (SeqPurge for paired-end, Skewer/ReadQC for single-end)
	if($paired)
	{
		$fastq_trimmed1 = $parser->tempFile("_trimmed.fastq.gz");
		$fastq_trimmed2 = $parser->tempFile("_trimmed.fastq.gz");
		$parser->exec(get_path("ngs-bits")."SeqPurge", "-in1 ".implode(" ", $in_for)." -in2 ".implode(" ", $in_rev)." -out1 $fastq_trimmed1 -out2 $fastq_trimmed2 -a1 ".$sys["adapter1_p5"]." -a2 ".$sys["adapter2_p7"]." -qc $qc_fastq -threads ".$threads." -qcut 0", true);
	}
	else
	{
		$parser->exec(get_path("ngs-bits")."ReadQC", "-in1 ".implode(" ", $in_for)." -out $qc_fastq", true);

		$fastq_trimmed1 = $parser->tempFile("_trimmed.fastq.gz");
		$skewer_stderr = $parser->tempFile("_skewer_stderr");
		$parser->exec("zcat", implode(" ", $in_for)." | ".get_path("skewer")." -x ".$sys["adapter1_p5"]." -y ".$sys["adapter2_p7"]." -m any --threads $threads --quiet --stdout -"." 2> $skewer_stderr | gzip -1 > $fastq_trimmed1", true);
		$parser->log("skewer log", file($skewer_stderr));
	}

	//mapping
	$args = array("-out $final_bam", "-threads $threads", "-in1 $fastq_trimmed1");
	// determine genome from system
	if ($genome == "") {
		$genome = get_path("data_folder")."/genomes/STAR/{$build}/";	
	}
	$args[] = "-genome $genome";
	if($paired) $args[] = "-in2 $fastq_trimmed2";
	if($dedup) $args[] = "-dedup";

	if ($downstream == "") {
		$downstream_arr = array();
	}
	else {
		$downstream_arr = explode(",", $downstream);
	}
	if (in_array("fu", $steps) && !in_array("chimeric", $downstream_arr)) {
		$downstream_arr[] = "chimeric";
		$parser->log("Enabling downstream chimeric file needed for fusion detection.");
	}
	if (count($downstream_arr) > 0) $args[] = "-downstream ".implode(",", $downstream_arr);

	if($sharedMemory)
	{
		if (in_array("fu", $steps)) //for STAR 2.5.2b this does not work, but it might change in newer STAR releases
		{
			$parser->log("Using shared memory and detecting fusion proteins is not possible at the same time. Disabling shared memory.");
		}
		else
		{
			$args[] = "-useSharedMemory";
		}
	}

	$parser->execTool("NGS/mapping_star.php", implode(" ", $args));

	//indel realignment
	if($abra)
	{
		if ($target_file == "")
		{
			$parser->log("No target file associated with system, generating whole genome bed file.");
			//create bed file for whole genome from genome fasta index
			$roi_bed = $parser->tempFile("fusion_roi");
			$parser->exec("awk", "'OFS=\"\t\" {print $1,0,$2}' ".get_path("data_folder")."/genomes/{$build}.fa.fai > $roi_bed", true);
		}
		else
		{
			$roi_bed = $target_file;
		}
		$parser->execTool("NGS/indel_realign_abra.php", "-in $final_bam -out $final_bam -roi $roi_bed -mer 0.01 -threads $threads -build $build");
	}
}

//read counting
$counts_raw = $prefix."_counts_raw.tsv";
$counts_fpkm = $prefix."_counts_fpkm.tsv";
if(in_array("rc", $steps))
{
	$args = array();
	if($paired) $args[] = "-paired";
	if($stranded) $args[] = "-stranded";
	$parser->execTool("NGS/read_counting_featureCounts.php", "-in $final_bam -out $counts_raw -threads $threads -gtfFile $gtfFile -featureType $featureType -gtfAttribute $gtfAttribute ".implode(" ", $args));

	//normalize read counts
	$parser->execTool("NGS/normalize_read_counts.php", "-in $counts_raw -out $counts_fpkm -method rpkm");
}

//annotate
if(in_array("an", $steps))
{
	$parser->execTool("NGS/annotate_count_file.php", "-in $counts_fpkm -out $counts_fpkm");
}

//detect fusions
if(in_array("fu",$steps))
{
	$fusion_tmp_folder = $parser->tempFolder();
	$chimeric_file = "{$prefix}_chimeric.tsv";
	if (!file_exists($chimeric_file)) trigger_error("Could not open chimeric file '$chimeric_file' needed for STAR-Fusion. Please re-run mapping step.", E_USER_ERROR);

	$parser->exec(get_path("STAR-Fusion"), "--genome_lib_dir ".get_path("data_folder")."/genomes/STAR-Fusion/$build -J $chimeric_file --output_dir {$fusion_tmp_folder}/", true);
	$parser->exec("cp", "{$fusion_tmp_folder}/star-fusion.fusion_candidates.final.abridged ".$prefix."_var_fusions.tsv", true);
}

//import to database
$log_db  = $prefix."_log_db.log";
if (in_array("db", $steps))
{
	if(file_exists($log_db)) unlink($log_db);
	$parser->execTool("NGS/db_check_gender.php", "-in $final_bam -pid $out_name");

	//update last_analysis column of processed sample in NGSD
	updateLastAnalysisDate($out_name, $final_bam);

	//import QC data
	$parser->execTool("NGS/db_import_qc.php", "-id $out_name -files $qc_fastq $qc_map -force");
}
?>
