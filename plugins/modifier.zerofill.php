<?php
require_once('modifier.zerofill.php');

/**
 * string zerofill(string, int)
 *
 * @param int $total_length
 */
function CTEPL__zerofill($target, $total_length)
{
  return Cylib__zerofill($target, $total_length);
}
?>
