<?php
/**
 * mixed escapenl(mixed)
 *
 * @param mixed  &$target
 */
function CTEPL__escapenl($target)
{
  return preg_replace("/\n|\r/", '', $target);
}
?>