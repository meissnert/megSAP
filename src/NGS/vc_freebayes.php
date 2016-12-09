<?php 
/** 
	@page vc_freebayes
	
	@todo Look into strand-bias filter for shotgun data?
*/

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

$basedir = dirname($_SERVER['SCRIPT_FILENAME'])."/../";

// add parameter for command line ${input1.metadata.bam_index}
// parse command line arguments
$parser = new ToolBase("vc_freebayes", "\$Rev: 912 $", "Variant calling with freebayes.");
$parser->addInfileArray("bam",  "Input files in BAM format. Space separated. Note: .bam.bai file is required!", false);
$parser->addOutfile("out", "Output file in VCF.GZ format.", false);
//optional
$parser->addInfile("target",  "Enrichment targets BED file.", true);
$parser->addString("build", "The genome build to use.", true, "hg19");
$parser->addFloat("min_af", "Minimum allele frequency cutoff used for variant calling.", true, 0.15);
$parser->addInt("min_mq", "Minimum mapping quality cutoff used for variant calling.", true, 1);
extract($parser->parse($argv));

//(1) set up variant calling pipeline
$genome = get_path("local_data")."/{$build}.fa";
$pipeline = array();

//create basic variant calls
$extras = array();
if(isset($target))
{
	$target_merged = $parser->tempFile(".bed");
	$parser->exec(get_path("ngs-bits")."BedMerge"," -in $target -out $target_merged", true);
	$extras[] = "-t $target_merged";
}
$extras[] = "--min-alternate-fraction $min_af";
$extras[] = "--min-mapping-quality $min_mq";
$extras[] = "--min-base-quality 10"; //max 10% error propbability
$extras[] = "--min-alternate-qsum 90"; //At least 3 good observations
$pipeline[] = array(get_path("freebayes"), "-b ".implode(" ",$bam)." -f $genome ".implode(" ", $extras));

//filter variants according to variant quality>5 , alternate observations>=3
$pipeline[] = array(get_path("vcflib")."vcffilter", "-f \"QUAL > 5 & AO > 2\"");

//split complex variants to primitives
//this step has to be performed before vcfbreakmulti - otherwise mulitallelic variants that contain both 'hom' and 'het' genotypes fail - see NA12878 amplicon test chr2:215632236-215632276
$pipeline[] = array(get_path("vcflib")."vcfallelicprimitives", "-kg");

//split multi-allelic variants
$pipeline[] = array(get_path("vcflib")."vcfbreakmulti", "");

//normalize all variants and align INDELs to the left
$pipeline[] = array(get_path("ngs-bits")."VcfLeftNormalize","-ref $genome");

//sort variants by genomic position
$pipeline[] = array(get_path("ngs-bits")."VcfStreamSort","");

//fix error in VCF file and strip unneeded information
$pipeline[] = array("php ".$basedir."NGS/vcf_fix.php", "", false);

//zip
$pipeline[] = array("bgzip", "-c > $out", false);

//(2) execute pipeline
$parser->execPipeline($pipeline, "variant calling");

//(3) index output file
$parser->exec("tabix", "-p vcf $out", false); //no output logging, because Toolbase::extractVersion() does not return

?>