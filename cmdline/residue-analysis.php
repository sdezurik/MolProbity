#!/usr/bin/env php
<?php # (jEdit options) :folding=explicit:collapseFolds=1:
/*****************************************************************************
    Processes one or more PDB files and outputs a list of all the
    Ramachanadran/rotamer/C-beta dev./clash outliers

 -> We assume all files already have H's added! <-

INPUTS (via $_SERVER['argv']):
    one or more PDB files

OUTPUTS:

*****************************************************************************/
// EVERY *top-level* page must start this way:
// 1. Define it's relationship to the root of the MolProbity installation.
// Pages in subdirectories of lib/ or public_html/ will need more "/.." 's.
    if(!defined('MP_BASE_DIR')) define('MP_BASE_DIR', realpath(dirname(__FILE__).'/..'));
// 2. Include core functionality - defines constants, etc.
    require_once(MP_BASE_DIR.'/lib/core.php');
    require_once(MP_BASE_DIR.'/lib/model.php');
    require_once(MP_BASE_DIR.'/lib/analyze.php');
    require_once(MP_BASE_DIR.'/lib/visualize.php');
// 3. Restore session data. If you don't want to access the session
// data for some reason, you must call mpInitEnvirons() instead.
    mpInitEnvirons();       // use std PATH, etc.
    //mpStartSession(true);   // create session dir
// 5. Set up reasonable values to emulate CLI behavior if we're CGI
    set_time_limit(0); // don't want to bail after 30 sec!
// 6. Unlimited memory for processing large files
    ini_set('memory_limit', -1);

#{{{ a_function_definition - sumary_statement_goes_here
############################################################################
/**
* Documentation for this function.
*/
//function someFunctionName() {}
#}}}########################################################################

# MAIN - the beginning of execution for this page
############################################################################
// Default options
$optClash     = true;
$optCbeta     = true;
$optRota      = true;
$optRama      = true;
$optOmega     = true;
$optGeom      = true;
$optCountOut  = true;
$outliersOnly = false;

$pdbFileList = array();
// First argument is the name of this script...
if(is_array($_SERVER['argv'])) foreach(array_slice($_SERVER['argv'], 1) as $arg)
{
    if($arg == '-noclash')           $optClash = false;
    elseif($arg == '-nocbeta')       $optCbeta = false;
    elseif($arg == '-norota')        $optRota = false;
    elseif($arg == '-norama')        $optRama = false;
    elseif($arg == '-noomega')       $optOmega = false;
    elseif($arg == '-nogeom')        $optGeom = false;
    elseif($arg == '-nocount')       $optCountOut = false;
    elseif($arg == '-outliers_only') $outliersOnly = true;
    else                        $pdbFileList[] = $arg;
}
if(count($pdbFileList) == 0)
    die("Must provide at least one PDB file on the command line!\n");

echo "#file_name,x-H_type,residue,res_high_B,mc_high_B";
if($optClash)   echo ",worst_clash,src_atom,dst_atom,dst_residue";
if($optCbeta)   echo ",CB_dev";
if($optRota)    echo ",rotamer_score,rotamer_eval,rotamer";
if($optRama)    echo ",rama_score,rama_eval,rama_type";
if($optOmega)   echo ",omega,omega_eval,omega_type";
if($optGeom)    echo ",num_length_out,worst_length,worst_length_value,worst_length_sigma,num_angle_out,worst_angle,worst_angle_value,worst_angle_sigma";
if($optCountOut) echo ",outlier_count,outlier_count_sep_geom";
echo "\n";


function summaryAnalysis($modelID)
{
    global $optClash, $optCbeta, $optRota, $optRama, $optOmega, $optGeom, $optCountOut, $outliersOnly;
    $out = "";

    //$filename = basename($infile);
    //$tmp = mpTempfile();

    $model =& $_SESSION['models'][$modelID];
    $reduce_blength = $_SESSION['reduce_blength'];
    //$bcutval = 40; TO-DO - make these user controllable
    //$ocutval = 10;

    $pdbfile = $_SESSION['dataDir'].'/'.MP_DIR_MODELS."/$model[pdb]";
    $rawDir  = $_SESSION['dataDir'].'/'.MP_DIR_RAWDATA;
        if(!file_exists($rawDir)) mkdir($rawDir, 0777);
    $filename = basename($pdbfile);

    // Make sure all residues are represented, and in the right order.
    $res = listResidues($pdbfile);
    $Bfact = listResidueBfactors($pdbfile);
    $resB = $Bfact['res'];
    $mcB = $Bfact['mc'];

    // Run analysis; load data
    if($optClash)
    {
        //runClashlist($pdbfile, "$rawDir/$model[prefix]clash.data", $reduce_blength);
        //$clash = loadClashlist("$rawDir/$model[prefix]clash.data");
        runClashscore($pdbfile, "$rawDir/$model[prefix]clash.data", $reduce_blength);
        $clash = loadClashscore("$rawDir/$model[prefix]clash.data");
    }
    if($optCbeta)
    {
        runCbetaDev($pdbfile, "$rawDir/$model[prefix]cbdev.data");
        $cbdev = loadCbetaDev("$rawDir/$model[prefix]cbdev.data");
        $badCbeta = findCbetaOutliers($cbdev);
    }
    if($optRota)
    {
        runRotamer($pdbfile, "$rawDir/$model[prefix]rota.data");
        $rota = loadRotamer("$rawDir/$model[prefix]rota.data");
        $badRota = findRotaOutliers($rota);
    }
    if($optRama)
    {
        runRamachandran($pdbfile, "$rawDir/$model[prefix]rama.data");
        $rama = loadRamachandran("$rawDir/$model[prefix]rama.data");
    }
    if($optOmega)
    {
        runOmegalyze($pdbfile, "$rawDir/$model[prefix]omega.data");
        $omega = loadOmegalyze("$rawDir/$model[prefix]omega.data");
    }
    if($optGeom)
    {
      runValidationReport($pdbfile, "$rawDir/$model[prefix]geom.data", "protein");
      $bonds = loadValidationBondReport("$rawDir/$model[prefix]geom.data", "protein");
      $angles = loadValidationAngleReport("$rawDir/$model[prefix]geom.data", "protein");
    }
    //unlink($tmp);

    foreach($res as $cnit)
    {
        $outCount = 0;
        $outCountSep = 0;

        if($optCountOut)
        {
          if(isset($clash['clashes'][$cnit])) {
            $outCount++;
            $outCountSep++;
          }
          if(isset($rota[$cnit]))
          {
            if($rota[$cnit]['eval'] == "OUTLIER") {
              $outCount++;
              $outCountSep++;
            }
          }
          if(isset($rama[$cnit]))
          {
            if($rama[$cnit]['eval'] == "OUTLIER") {
              $outCount++;
              $outCountSep++;
            }
          }
          if(isset($omega[$cnit])) //non-proline cis-peptides and all twisted peptides are probable outliers
          {
            if($omega[$cnit]['conf'] == "Twisted") {
              $outCount++;
              $outCountSep++;
            }
            elseif(($omega[$cnit]['conf'] == "Cis") and ($omega[$cnit]['type'] == "General")) {
              $outCount++;
              $outCountSep++;
            }
          }
          if(isset($badCbeta[$cnit]))         $outCountSep++;
          if(isset($bonds[$cnit]))
          {
            if($bonds[$cnit]['count']+0 > 0) $outCountSep++;
          }
          if(isset($angles[$cnit]))
          {
            if($angles[$cnit]['count']+0 > 0) $outCountSep++;
          }
          if((isset($badCbeta[$cnit]))or((isset($bonds[$cnit]))and($bonds[$cnit]['count']+0 > 0))or((isset($angles[$cnit]))and($angles[$cnit]['count']+0 > 0))) {
            $outCount++;
          }
          //echo ",".$outCount.",".$outCountSep;
        }

        if($outliersOnly)
        {
          if($outCount == 0)
            continue;
        }
        echo "$filename,$reduce_blength,$cnit,$resB[$cnit],$mcB[$cnit]";

        if($optClash)
        {
            if(isset($clash['clashes'][$cnit]))
                echo ",".$clash['clashes'][$cnit].",".$clash['clashes-with'][$cnit]['srcatom'].",".$clash['clashes-with'][$cnit]['dstatom'].",".$clash['clashes-with'][$cnit]['dstcnit'];
            else echo ",,,,";
        }
        if($optCbeta)
        {
            if(isset($badCbeta[$cnit]))
                echo ",".$badCbeta[$cnit];
            else echo ",";
        }
        if($optRota)
        {
            if(isset($rota[$cnit]))
            {
                echo ",".$rota[$cnit]['scorePct'].",".$rota[$cnit]['eval'];
                //if($rota[$cnit]['eval'] == "OUTLIER") echo ",OUTLIER";
                //else echo ",".$rota[$cnit]['rotamer'];
                echo ",".$rota[$cnit]['rotamer'];
            }
            else echo ",,";
        }
        if($optRama)
        {
            if(isset($rama[$cnit]))
                echo ",".$rama[$cnit]['scorePct'].",".$rama[$cnit]['eval'].",".$rama[$cnit]['type'];
            else echo ",,,";
        }
        if($optOmega)
        {
            if(isset($omega[$cnit]))
                echo ",".$omega[$cnit]['omega'].",".$omega[$cnit]['conf'].",".$omega[$cnit]['type'];
            else echo ",,,";
        }
        if($optGeom)
        {
          if(isset($bonds[$cnit]))
            echo ",".$bonds[$cnit]['count'].",".$bonds[$cnit]['measure'].",".$bonds[$cnit]['value'].",".$bonds[$cnit]['sigma'];
          else echo ",,,,";
          if(isset($angles[$cnit]))
            echo ",".$angles[$cnit]['count'].",".$angles[$cnit]['measure'].",".$angles[$cnit]['value'].",".$angles[$cnit]['sigma'];
          else echo ",,,,";
        }
        if($optCountOut)
        {
         /* $outCount = 0;
          $outCountSep = 0;
          if(isset($clash['clashes'][$cnit])) {
            $outCount++;
            $outCountSep++;
          }
          if(isset($rota[$cnit]))
          {
            if($rota[$cnit]['scorePct']+0 <= 1.0) {
              $outCount++;
              $outCountSep++;
            }
          }
          if(isset($rama[$cnit]))
          {
            if($rama[$cnit]['eval'] == "OUTLIER") {
              $outCount++;
              $outCountSep++;
            }
          }
          if(isset($badCbeta[$cnit]))         $outCountSep++;
          if(isset($bonds[$cnit]))
          {
            if($bonds[$cnit]['count']+0 > 0) $outCountSep++;
          }
          if(isset($angles[$cnit]))
          {
            if($angles[$cnit]['count']+0 > 0) $outCountSep++;
          }
          if((isset($badCbeta[$cnit]))or((isset($bonds[$cnit]))and($bonds[$cnit]['count']+0 > 0))or((isset($angles[$cnit]))and($angles[$cnit]['count']+0 > 0))) {
            $outCount++;
          }*/
          echo ",".$outCount.",".$outCountSep;
        }
        echo "\n";
    }
}

// Loop through all PDBs
foreach($pdbFileList as $infile)
{
  if(is_file($infile))
  {
    mpStartSession(true); // create a new session
    $inpath = $infile;
    // Need to ignore segIDs for stupid Top500 with seg new_ for all H
    $id = addModelOrEnsemble(
            $inpath,
            basename($inpath),
            false,
            true,
            true,
            false);
    if(isset($_SESSION['ensembles'][$id]))
    {
        foreach($_SESSION['ensembles'][$id]['models'] as $modelID)
        {
            //echo basename($_SESSION['models'][$modelID]['pdb']);
            echo summaryAnalysis($modelID);
        }
    }
    else
    {
        //echo basename($infile);
        echo summaryAnalysis($id);
    }

    // Clean up and go home
    mpDestroySession();

  }

}

############################################################################
// Clean up and go home
?>