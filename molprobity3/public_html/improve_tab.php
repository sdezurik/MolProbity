<?php # (jEdit options) :folding=explicit:collapseFolds=1:
/*****************************************************************************
    Provides a UI for improving models automatically with expert-system tools.
*****************************************************************************/
// EVERY *top-level* page must start this way:
// 1. Define it's relationship to the root of the MolProbity installation.
// Pages in subdirectories of lib/ or public_html/ will need more "/.." 's.
    if(!defined('MP_BASE_DIR')) define('MP_BASE_DIR', realpath(dirname(__FILE__).'/..'));
// 2. Include core functionality - defines constants, etc.
    require_once(MP_BASE_DIR.'/lib/core.php');
// 3. Restore session data. If you don't want to access the session
// data for some reason, you must call mpInitEnvirons() instead.
    mpStartSession();

#{{{ a_function_definition - sumary_statement_goes_here
############################################################################
/**
* Documentation for this function.
*/
//function someFunctionName() {}
#}}}########################################################################

    echo mpPageHeader("Improve models", "improve");
?>


<!-- List of current models available -->
<?php if(count($_SESSION['models']) > 0) { ?>
<p>
<h3>Models available for improvement:</h3>
<table width="100%" border="0" cellspacing="0" cellpadding="2">
<?php
    $c = MP_TABLE_ALT1;
    foreach($_SESSION['models'] as $id => $model)
    {
        // Alternate row colors:
        $c == MP_TABLE_ALT1 ? $c = MP_TABLE_ALT2 : $c = MP_TABLE_ALT1;
        echo " <tr bgcolor='$c' align='center'>\n";
        echo "  <td align='left'><b>$id</b></td>\n";
        if($model['isBuilt']) echo "  <td><span class='inactive'>already Reduced</span></td>\n";
        else echo "  <td><a href='improve_reduce_setup.php?$_SESSION[sessTag]&model=$id'>Reduce -build</a></td>\n";
        echo " </tr>\n";
        echo " <tr bgcolor='$c'>\n";
        echo "  <td colspan='2'><small>$model[history]</small></td>\n";
        echo " </tr>\n";
    }
?>
</table>
<!-- <hr>
<h3>Reduce -build</h3>
Details about what Reduce -build does should go here.
<hr>
<h3>SSWING</h3>
Details about what SSWING does should go here.
-->
<?php }
else echo "No models have been provided yet. Please <a href='upload_tab.php?$_SESSION[sessTag]'>get input models</a> first.";
?>


<?php echo mpPageFooter(); ?>
