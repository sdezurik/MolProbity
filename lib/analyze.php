<?php # (jEdit options) :folding=explicit:collapseFolds=1:
/*****************************************************************************
    Provides functions for producing analysis data from outside programs
    and for loading and interpretting that data.
    
    Many functions work with a column-formatted residue name
    stored in exactly 9 characters, like this: 'cnnnnittt'
        c: Chain ID, space for none
        n: sequence number, right justified, space padded
        i: insertion code, space for none
        t: residue type (ALA, LYS, etc.), all caps, left justified, space padded
*****************************************************************************/
require_once(MP_BASE_DIR.'/lib/strings.php');
require_once(MP_BASE_DIR.'/lib/model.php');     // for running Reduce as needed
require_once(MP_BASE_DIR.'/lib/visualize.php'); // for making kinemages

#{{{ runAnalysis - generate (a subset of) all the validation criteria
############################################################################
/**
* This is the uber-validation function that calls everything below.
* It is suited for use from either the web or command line interface.
* This only makes sense in terms of an active session.
*   modelID             ID code for model to process
*   opts[doRama]        a flag to create Ramachandran plots
*   opts[doRota]        a flag to find bad rotamers
*   opts[doCbeta]       a flag to make 2- and 3-D Cbeta deviation plots
*   opts[doAAC]         a flag to make all-atom contact kinemages
*   opts[doMultiKin]    a flag to make the multi-criterion kinemage
*   opts[doMultiChart]  a flag to make the multi-criterion chart
*   opts[doAll]         a flag to do all of the above
* If opts is not set, nothing will be done!
*/
function runAnalysis($modelID, $opts)
{
    $model  = $_SESSION['models'][$modelID];
    $infile = "$model[dir]/$model[pdb]";
    
    // The same conditionals cut and pasted from below, used to determine ahead of time what we're going to do
    if(($opts['doAll'] || $opts['doAAC'] || $opts['doMultiChart'] || $opts['doMultiKin']) && (! $model['isReduced'])) $tasks['reduce'] = "Add H with <code>reduce -keep -noadjust -his</code>";
    if($opts['doAll'] || $opts['doCbeta'] || $opts['doMultiChart']) $tasks['cbdata'] = "Do C-beta analysis";
    if($opts['doAll'] || $opts['doRota'] || $opts['doMultiChart'] || $opts['doMultiKin']) $tasks['rotadata'] = "Do rotamer analysis";
    if($opts['doAll'] || $opts['doRama'] || $opts['doMultiChart'] || $opts['doMultiKin']) $tasks['ramadata'] = "Do Ramachandran analysis";
    if($opts['doAll'] || $opts['doAAC'] || $opts['doMultiChart']) $tasks['clashlist'] = "Do clash analysis with <code>clashlist</code>";
    if($opts['doAll'] || $opts['doMultiChart']) $tasks['mcchart'] = "Create multi-criteria chart";
    if($opts['doAll'] || $opts['doMultiKin']) $tasks['mckin'] = "Create multi-criteria kinemage";
    if($opts['doAll'] || $opts['doRama']) $tasks['ramaplot'] = "Create Ramachandran plots";
    if($opts['doAll'] || $opts['doCbeta']) $tasks['cbkin'] = "Create C-beta deviation kinemages";
    if($opts['doAll'] || $opts['doAAC']) $tasks['aackin'] = "Create all-atom contacts kinemage";

    ////////////////////////////////////////////////////////////////////////////
    // Check for hydrogens and add them if needed.
    if(($opts['doAll'] || $opts['doAAC'] || $opts['doMultiChart'] || $opts['doMultiKin']) && (! $model['isReduced']))
    {
        setProgress($tasks, 'reduce'); // updates the progress display if running as a background job
        $outfile = $model['id']."nbH.pdb";
        $outpath = "$model[dir]/$outfile";
        reduceNoBuild($infile, $outpath);
        $_SESSION['models'][$modelID]['pdb'] = $outfile;
        $_SESSION['models'][$modelID]['isReduced'] = true;
        
        $model  = $_SESSION['models'][$modelID];
        $infile = "$model[dir]/$model[pdb]";
    }
    
    ////////////////////////////////////////////////////////////////////////////
    // Data collection for multi-crit chart, etc.
    
    // C-betas
    if($opts['doAll'] || $opts['doCbeta'] || $opts['doMultiChart'])
    {
        setProgress($tasks, 'cbdata'); // updates the progress display if running as a background job
        $outfile = "$model[dir]/$model[prefix]cbdev.data";
        runCbetaDev($infile, $outfile);
        $cbdev = loadCbetaDev($outfile);
        $_SESSION['models'][$modelID]['badCbeta'] = findCbetaOutliers($cbdev);
    }
    
    // Rotamers
    if($opts['doAll'] || $opts['doRota'] || $opts['doMultiChart'] || $opts['doMultiKin'])
    {
        setProgress($tasks, 'rotadata'); // updates the progress display if running as a background job
        $outfile = "$model[dir]/$model[prefix]rota.data";
        runRotamer($infile, $outfile);
        $rota = loadRotamer($outfile);
        $_SESSION['models'][$modelID]['badRota'] = findRotaOutliers($rota);
    }
    
    // Ramachandran
    if($opts['doAll'] || $opts['doRama'] || $opts['doMultiChart'] || $opts['doMultiKin'])
    {
        setProgress($tasks, 'ramadata'); // updates the progress display if running as a background job
        $outfile = "$model[dir]/$model[prefix]rama.data";
        runRamachandran($infile, $outfile);
        $rama = loadRamachandran($outfile);
        $_SESSION['models'][$modelID]['badRama'] = findRamaOutliers($rama);
    }
    
    // Clashes
    if($opts['doAll'] || $opts['doAAC'] || $opts['doMultiChart'])
    {
        setProgress($tasks, 'clashlist'); // updates the progress display if running as a background job
        $outfile = "$model[dir]/$model[prefix]clash.data";
        runClashlist($infile, $outfile);
        $clash = loadClashlist($outfile);
        $_SESSION['models'][$modelID]['badClash'] = findClashOutliers($clash);
    }
    
    // Find all residues on the naughty list
    // First index is 9-char residue name
    // Second index is 'cbdev', 'rota', 'rama', or 'clash'
    if($opts['doAll'] || $opts['doMultiChart'])
    {
        setProgress($tasks, 'mcchart'); // updates the progress display if running as a background job
        // TODO: Integrate outlier information from above analyses into a chart
    }
    
    ////////////////////////////////////////////////////////////////////////////
    // Kinemages and other visualizations
    
    // Multi-criterion kinemage
    if($opts['doAll'] || $opts['doMultiKin'])
    {
        setProgress($tasks, 'mckin'); // updates the progress display if running as a background job
        $outfile = "$model[dir]/$model[prefix]multi.kin";
        if(file_exists($outfile)) unlink($outfile);
        
        $h = fopen($outfile, 'a');
        fwrite($h, "@kinemage 1\n@group {macromol.} dominant off\n");
        fclose($h);
        exec("prekin -append -nogroup -scope -show 'mc(white),sc(brown),hy(gray),ht(sky)' $infile >> $outfile");
        
        $h = fopen($outfile, 'a');
        fwrite($h, "@group {waters} dominant off\n");
        fclose($h);
        exec("prekin -append -nogroup -scope -show 'wa(bluetint)' $infile >> $outfile");
        
        $h = fopen($outfile, 'a');
        fwrite($h, "@group {Ca trace} dominant\n");
        fclose($h);
        exec("prekin -append -nogroup -scope -show 'ca(gray)' $infile >> $outfile");
        
        makeAltConfKin($infile, $outfile);
        makeBadRamachandranKin($infile, $outfile, $rama);
        makeBadRotamerKin($infile, $outfile, $rota);
        makeBadCbetaBalls($infile, $outfile);
        makeBadDotsVisible($infile, $outfile, true); // if false, don't write hb, vdw
    }
    
    // Ramachandran plots
    if($opts['doAll'] || $opts['doRama'])
    {
        setProgress($tasks, 'ramaplot'); // updates the progress display if running as a background job
        makeRamachandranKin($infile, "$model[dir]/$model[prefix]rama.kin");
        makeRamachandranPDF($infile, "$model[dir]/$model[prefix]rama.pdf");
        //makeRamachandranImage($infile, "$model[dir]/$model[prefix]rama.jpg");
        //convertKinToPostscript("$model[dir]/$model[prefix]rama.kin");
    }
    
    // C-beta deviations
    // In the future, we might use a custom lots kin here (e.g. with half-bond colors)
    if($opts['doAll'] || $opts['doCbeta'])
    {
        setProgress($tasks, 'cbkin'); // updates the progress display if running as a background job
        $outfile = "$model[dir]/$model[prefix]cb3d.kin";
        exec("prekin -lots $infile > $outfile");
        makeCbetaDevBalls($infile, $outfile);
        makeCbetaDevPlot($infile, "$model[dir]/$model[prefix]cb2d.kin");
    }
    
    // All-atom contacts
    // We might also want to not calculate H-bonds or VDW dots
    // In the future, we might use a custom lots kin here (e.g. with half-bond colors)
    if($opts['doAll'] || $opts['doAAC'])
    {
        setProgress($tasks, 'aackin'); // updates the progress display if running as a background job
        $outfile = "$model[dir]/$model[prefix]aac.kin";
        exec("prekin -lots $infile > $outfile");
        makeSidechainDots($infile, $outfile);
        //$outfile = "$model[dir]/$model[prefix]aac-mc.kin";
        //exec("prekin -lots $infile > $outfile");
        makeMainchainDots($infile, $outfile);
    }
    
    setProgress($tasks, null); // everything is finished
}
#}}}########################################################################

#{{{ runCbetaDev - generates numeric info about CB deviations
############################################################################
function runCbetaDev($infile, $outfile)
{
    exec("prekin -cbdevdump $infile > $outfile");
}
#}}}########################################################################

#{{{ loadCbetaDev - loads Prekin cbdevdump output into an array
############################################################################
/**
* Returns an array of entries, one per residue. Their keys:
*   altConf         alternate conformer flag, or ' ' for none
*   resName         a formatted name for the residue: 'cnnnnittt'
*                       c: Chain ID, space for none
*                       n: sequence number, right justified, space padded
*                       i: insertion code, space for none
*                       t: residue type (ALA, LYS, etc.), all caps,
*                          left justified, space padded
*   resType         3-letter residue code (e.g. ALA)
*   chainID         1-letter chain ID or ' '
*   resNum          residue number
*   insCode         insertion code or ' '
*   dev             deviation distance, in Angstroms
*   dihedral        N-CA-idealCB-actualCB angle, in degrees
*   occ             occupancy, between 0 and 1
*/
function loadCbetaDev($datafile)
{
    $data = file($datafile);
    foreach($data as $line)
    {
        $line = trim($line);
        if($line != "" && !startsWith($line, 'pdb:alt:res:'))
        {
            $line = explode(':', $line);
            $entry = array(
                'altConf'   => strtoupper($line[1]),
                'resType'   => strtoupper($line[2]),
                'chainID'   => strtoupper($line[3]),
                'resNum'    => trim(substr($line[4], 0, -1)) + 0,
                'insCode'   => substr($line[4], -1),
                'dev'       => $line[5] + 0,
                'dihedral'  => $line[6] + 0,
                'occ'       => $line[7] + 0
            );
            $entry['resName']   = $entry['chainID']
                                . str_pad($entry['resNum'], 4, ' ', STR_PAD_LEFT)
                                . $entry['insCode']
                                . str_pad($entry['resType'], 3, ' ', STR_PAD_RIGHT);
            $ret[] = $entry;
        }
    }
    return $ret;
}
#}}}########################################################################

#{{{ findCbetaOutliers - evaluates residues for bad score
############################################################################
/**
* Returns an array of 9-char residue names for residues that
* fall outside the allowed boundaries for this criteria.
* Inputs are from appropriate loadXXX() function above.
*/
function findCbetaOutliers($cbdev)
{
    $worst = array();
    if(is_array($cbdev)) foreach($cbdev as $res)
    {
        if($res['dev'] >= 0.25)
            $worst[$res['resName']] = $res['dev'];
    }
    ksort($worst); // Put the residues into a sensible order
    return $worst;
}
#}}}########################################################################

#{{{ runClashlist - generates clash data with Clashlist
############################################################################
function runClashlist($infile, $outfile)
{
    exec("clashlist $infile > $outfile");
}
#}}}########################################################################

#{{{ loadClashlist - loads Clashlist output into an array
############################################################################
/**
* Returns an array with the following keys:
*   scoreAll        the overall clashscore
*   scoreBlt40      the score for atoms with B < 40
*   clashes         an array with 'cnnnnittt' residue names as keys
*                   (see loadCbetaDev() for explanation of naming)
*                   and maximum clashes as values (positive Angstroms).
*                   NB: only clashes >= 0.40A are currently listed.
*/
function loadClashlist($datafile)
{
    $data = file($datafile);
    $sum = array_values(array_slice($data, -2)); // last 2 lines with new indexes
    $scores = explode(':', $sum[0]);
    $ret['scoreAll']    = $scores[2] + 0;
    $ret['scoreBlt40']  = $scores[3] + 0;
    
    // Parse data about individual clashes
    $clashes = array(); // in case there are no clashes
    foreach($data as $datum)
    {
        // Ignore blank lines and #sum... lines
        // That leaves lines starting with colons.
        if($datum{0} == ':')
        {
            $line = explode(':', $datum);
            $res1 = substr($line[2], 0, 9);
            $res2 = substr($line[3], 0, 9);
            $dist = abs(trim($line[4])+0);
            if(!isset($clashes[$res1]) || $clashes[$res1] < $dist)
                $clashes[$res1] = $dist;
            if(!isset($clashes[$res2]) || $clashes[$res2] < $dist)
                $clashes[$res2] = $dist;
        }
    }
    $ret['clashes'] = $clashes;
    
    return $ret;
}
#}}}########################################################################

#{{{ findClashOutliers - evaluates residues for bad score
############################################################################
/**
* Returns an array of 9-char residue names for residues that
* fall outside the allowed boundaries for this criteria.
* Inputs are from appropriate loadXXX() function above.
*/
function findClashOutliers($clash)
{
    $worst = array();
    if(is_array($clash)) foreach($clash['clashes'] as $res => $dist)
    {
        if($dist >= 0.4)
            $worst[$res] = $dist;
    }
    ksort($worst); // Put the residues into a sensible order
    return $worst;
}
#}}}########################################################################

#{{{ runRotamer - generates rotamer analysis data
############################################################################
function runRotamer($infile, $outfile)
{
    exec("java -cp ".MP_BASE_DIR."/lib/hless.jar hless.Rotamer -raw $infile > $outfile");
}
#}}}########################################################################

#{{{ loadRotamer - loads Rotamer output into an array
############################################################################
/**
* Returns an array of entries, one per residue. Their keys:
*   resName         a formatted name for the residue: 'cnnnnittt'
*                       c: Chain ID, space for none
*                       n: sequence number, right justified, space padded
*                       i: insertion code, space for none
*                       t: residue type (ALA, LYS, etc.), all caps,
*                          left justified, space padded
*   resType         3-letter residue code (e.g. ALA)
*   chainID         1-letter chain ID or ' '
*   resNum          residue number
*   insCode         insertion code or ' '
*   scorePct        the percentage score from 0 (bad) to 100 (good)
*   chi1            the chi-1 angle
*   chi2            the chi-2 angle ("" for none)
*   chi3            the chi-3 angle ("" for none)
*   chi4            the chi-4 angle ("" for none)
*/
function loadRotamer($datafile)
{
    $data = array_slice(file($datafile), 1); // drop first line
    foreach($data as $line)
    {
        $line = explode(':', rtrim($line));
        $decomp = decomposeResName($line[0]);
        $ret[] = array(
            'resName'   => $line[0],
            'resType'   => $decomp['resType'],
            'chainID'   => $decomp['chainID'],
            'resNum'    => $decomp['resNum'],
            'insCode'   => $decomp['insCode'],
            'scorePct'  => $line[1] + 0,
            'chi1'      => $line[2] + 0,
            'chi2'      => $line[3] + 0,
            'chi3'      => $line[4] + 0,
            'chi4'      => $line[5] + 0
        );
    }
    return $ret;
}
#}}}########################################################################

#{{{ findRotaOutliers - evaluates residues for bad score
############################################################################
/**
* Returns an array of 9-char residue names for residues that
* fall outside the allowed boundaries for this criteria.
* Inputs are from appropriate loadXXX() function above.
*/
function findRotaOutliers($rota)
{
    $worst = array();
    if(is_array($rota)) foreach($rota as $res)
    {
        if($res['scorePct'] <= 1.0)
            $worst[$res['resName']] = $res['scorePct'];
    }
    ksort($worst); // Put the residues into a sensible order
    return $worst;
}
#}}}########################################################################

#{{{ runRamachandran - generates rotamer analysis data
############################################################################
function runRamachandran($infile, $outfile)
{
    exec("java -cp ".MP_BASE_DIR."/lib/hless.jar hless.Ramachandran -nokin -raw $infile > $outfile");
}
#}}}########################################################################

#{{{ loadRamachandran - loads Ramachandran output into an array
############################################################################
/**
* Returns an array of entries, one per residue. Their keys:
*   resName         a formatted name for the residue: 'cnnnnittt'
*                       c: Chain ID, space for none
*                       n: sequence number, right justified, space padded
*                       i: insertion code, space for none
*                       t: residue type (ALA, LYS, etc.), all caps,
*                          left justified, space padded
*   resType         3-letter residue code (e.g. ALA)
*   chainID         1-letter chain ID or ' '
*   resNum          residue number
*   insCode         insertion code or ' '
*   scorePct        the percentage score from 0 (bad) to 100 (good)
*   phi             the phi angle
*   psi             the psi angle
*   eval            "Favored", "Allowed", or "OUTLIER"
*   type            "General case", "Glycine", "Proline", or "Pre-proline"
*/
function loadRamachandran($datafile)
{
    $data = array_slice(file($datafile), 1); // drop first line
    foreach($data as $line)
    {
        $line = explode(':', rtrim($line));
        $decomp = decomposeResName($line[0]);
        $ret[] = array(
            'resName'   => $line[0],
            'resType'   => $decomp['resType'],
            'chainID'   => $decomp['chainID'],
            'resNum'    => $decomp['resNum'],
            'insCode'   => $decomp['insCode'],
            'scorePct'  => $line[1] + 0,
            'phi'       => $line[2] + 0,
            'psi'       => $line[3] + 0,
            'eval'      => $line[4],
            'type'      => $line[5]
        );
    }
    return $ret;
}
#}}}########################################################################

#{{{ findRamaOutliers - evaluates residues for bad score
############################################################################
/**
* Returns an array of 9-char residue names for residues that
* fall outside the allowed boundaries for this criteria.
* Inputs are from appropriate loadXXX() function above.
*/
function findRamaOutliers($rama)
{
    $worst = array();
    if(is_array($rama)) foreach($rama as $res)
    {
        if($res['eval'] == 'OUTLIER')
            $worst[$res['resName']] = $res['eval'];
    }
    ksort($worst); // Put the residues into a sensible order
    return $worst;
}
#}}}########################################################################

#{{{ decomposeResName - breaks a 9-character packed name into pieces
############################################################################
/**
* Decomposes this:
*   resName         a formatted name for the residue: 'cnnnnittt'
*                       c: Chain ID, space for none
*                       n: sequence number, right justified, space padded
*                       i: insertion code, space for none
*                       t: residue type (ALA, LYS, etc.), all caps,
*                          left justified, space padded
*
* Into this (as an array):
*   resType         3-letter residue code (e.g. ALA)
*   chainID         1-letter chain ID or ' '
*   resNum          residue number
*   insCode         insertion code or ' '
*/
function decomposeResName($name)
{
    return array(
        'resType'   => substr($name, 6, 3),
        'chainID'   => substr($name, 0, 1),
        'resNum'    => trim(substr($name, 1, 4))+0,
        'insCode'   => substr($name, 5, 1)
    );
}
#}}}########################################################################

#{{{ pdbComposeResName - makes a 9-char res ID from a PDB ATOM line
############################################################################
function pdbComposeResName($pdbline)
{
    return substr($pdbline, 21, 6) . substr($pdbline, 17, 3);
}
#}}}########################################################################

#{{{ findAltConfs - parses a PDB file for residues with mc and/or sc alts
############################################################################
/**
* Returns NULL if the file could not be read.
* Otherwise, returns an array of arrays of booleans.
* First key is 'mc', 'sc', or 'all';
* second key is the 9-char residue ID.
*/
function findAltConfs($infile)
{
    $mcAtoms = array(" N  " => true, " CA " => true, " C  " => true, " O  " => true,
        " H  " => true, " HA " => true, "1HA " => true, "2HA " => true);
    
    $out = array('all' => array(), 'mc' => array(), 'sc' => array());
    $in = fopen($infile, "r");
    if(!$in) return NULL;
    while(!feof($in))
    {
        $s = fgets($in, 1024);
        $alt = $s{16};
        if($alt != ' ' && (startsWith($s, "ATOM") || startsWith($s, "HETATM")))
        {
            $res    = pdbComposeResName($s);
            $atom   = substr($s, 12, 4);
            $out['all'][$res] = true;
            
            if($mcAtoms[$atom])
                $out['mc'][$res] = true;
            else
                $out['sc'][$res] = true;
        }
    }
    fclose($in);
    
    return $out;
}
#}}}########################################################################

#{{{ computeResCenters - finds (x,y,z) for residue (pseudo) center-of-mass from PDB
############################################################################
/**
* Returns NULL if the file could not be read.
* Otherwise, an array of arrays
* where the first key is the 9-char residue code
* and the second key is 'x', 'y', or 'z'.
* Does not account for the possibility of multiple MODELs
*/
function computeResCenters($infile)
{
    $out = array(); // x, y, z
    $cnt = array(); // how many atoms have been tallied
    
    $in = fopen($infile, "r");
    if(!$in) return NULL;
    while(!feof($in))
    {
        $s = fgets($in, 1024);
        if(startsWith($s, "ATOM") || startsWith($s, "HETATM"))
        {
            $res = pdbComposeResName($s);
            $out[$res]['x'] += substr($s, 30, 8) + 0.0;
            $out[$res]['y'] += substr($s, 38, 8) + 0.0;
            $out[$res]['z'] += substr($s, 46, 8) + 0.0;
            $cnt[$res]      += 1;
        }
    }
    fclose($in);
    
    foreach($cnt as $res => $num)
    {
        $out[$res]['x'] /= $num;
        $out[$res]['y'] /= $num;
        $out[$res]['z'] /= $num;
    }
    
    return $out;
}
#}}}########################################################################

#{{{ groupAdjacentRes - structures a list of residues into chains and "runs"
############################################################################
/**
* Given a list of 9-char residue codes as the values (not keys) of an array,
* a new data structure is created where
* the first index is a one-char chain ID,
* the second index is an arbitrary run number,
* the third index is arbitrary, and
* the value is the 9-char residue code.
* The so-called "runs" are just residues that were adjacent in the input list
* and had sequence numbers that differed by 1 (or 0).
*/
function groupAdjacentRes($resList)
{
    $out = array();
    if(is_array($resList)) foreach($resList as $res)
    {
        $num = substr($res, 1, 4) + 0;
        // If old run is ending, append it and start fresh:
        if(isset($run) && !($num - $prevNum <= 1 && $chainID == $res{0}))
        {
            $out[$chainID][] = $run;
            unset($run);
        }
        // Append this residue to the current run (which is potentially empty)
        $prevNum    = $num;
        $chainID    = $res{0};
        $run[]      = $res;
    }
    
    // Append the last run, if any
    if(isset($run)) $out[$chainID][] = $run;
    
    return $out;
}
#}}}########################################################################

#{{{ a_function_definition - sumary_statement_goes_here
############################################################################
/**
* Documentation for this function.
*/
//function someFunctionName() {}
#}}}########################################################################

#{{{ a_function_definition - sumary_statement_goes_here
############################################################################
/**
* Documentation for this function.
*/
//function someFunctionName() {}
#}}}########################################################################

#{{{ a_function_definition - sumary_statement_goes_here
############################################################################
/**
* Documentation for this function.
*/
//function someFunctionName() {}
#}}}########################################################################

#{{{ a_function_definition - sumary_statement_goes_here
############################################################################
/**
* Documentation for this function.
*/
//function someFunctionName() {}
#}}}########################################################################
?>
