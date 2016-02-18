<?php
/**
 * string gender(&string, string, string)
 *
 * @param string $target
 * @param string $m        The string to return when we have a male case.
 * @param string $f        The string to return when we have a female case.
 */
function CTEPL__gender($target, $m, $f)
{
  if(strtolower($target) == 'm') return $m;
  else return $f;
}
?>