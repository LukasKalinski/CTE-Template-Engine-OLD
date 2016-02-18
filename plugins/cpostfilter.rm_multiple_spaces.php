<?php

/**
 * string rm_multiple_spaces(CTE, string)
 * Removes multiple spaces.
 */
function CTEPL__rm_multiple_spaces($string)
{
  $string = preg_replace('/((?:\{|\})+)\s+((?:\{|\})+)/', '\1\2', $string);
  $string = preg_replace('/((?:\{|\})+)\s+((?:\{|\})+)/', '\1\2', $string);
  $string = preg_replace('/(\s)\s+/', '$1', $string);
  
  return $string;
}
?>