<?php
/**
 * @desc Appends all $_GET-vars onto $target.
 * @param string $target
 */
function CTEPL__urlappendgetvars($target)
{
  $target .= '?';
  foreach($_GET as $key => $value)
    $target .= $key.'='.urlencode($value).'&';
  return substr($target, 0, -1);
}
?>