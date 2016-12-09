<?php

/*
	@page indel_realign
*/

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

// parse command line arguments
$parser = new ToolBase("indel_realign", "\$Rev: 3$", "Perform InDel-realignment for mapping results using GATK.");
$parser->addInfile("in",  "Input file in bam format.", false);
$parser->addString("prefix", "String to add to output files. Might include sub-directories.", false, NULL);
//optional
$parser->addString("genome", "Path to GATK reference genome in fasta format.", true, get_path("data_folder")."genomes/GATK/hg19/hg19_GATK.fa");
extract($parser->parse($argv));

$out=create_path($prefix);
$sampleName = $out[0];
$outdir = $out[1];

$parser->log("indel_realign output directory=$outdir");

//build command
$arguments = array();

$arguments[] = "-R $genome";
$arguments[] = "-I $in";
$arguments[] = "-o ";

//execute indel realignment step
$parser->log("Starting GATK RealignerTargetCreator");
$intervalsFile=$parser->tempFile(".intervals");

$parser->exec(get_path("GATK"), "-T RealignerTargetCreator -I $in -R $genome -o $intervalsFile", true);
$parser->exec(get_path("GATK"), "-T IndelRealigner -I $in -R $genome --targetIntervals $intervalsFile -o ${outdir}${sampleName}.realign.bam -rf NotPrimaryAlignment", true);
//$parser->exec(get_path("samtools"), " index ${outdir}${sampleName}.realign.bam", true); GATK creates index file on the fly
// Just rename the index file generated by GATK
rename("${outdir}${sampleName}.realign.bai", "${outdir}${sampleName}.realign.bam.bai")
?>