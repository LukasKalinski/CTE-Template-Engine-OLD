<?php
require_once('core.wrap_php_code.php');

/**
 * wrap_php_printout(string)
 */
function CTECF__wrap_php_printout($str)
{
  if(!empty($str))
  {
    return CTECF__wrap_php_code('echo '.$str.';');
  }
  else
  {
    return '';
  }
}
?>
