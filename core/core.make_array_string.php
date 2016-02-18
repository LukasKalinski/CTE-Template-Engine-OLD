<?php
require_once('modifier.squote.php');

/**
 * string make_array_string(string)
 * Transforms an array to a string.
 *
 * Example:
 * $arr = array(1,2,3);
 * echo CTECF__make_array_string($arr); // Output: array(0=>1,1=>2,2=>3)
 *
 * @param mixed[] $array
 */
function CTECF__make_array_string($array, $squote_values=true)
{
  $array_str = 'array(';
  foreach($array as $key => $value)
  {
    if(is_numeric($key))
    {
      $array_str .= $key;
    }
    else
    {
      $array_str .= Cylib__squote($key);
    }
    
    $array_str .= '=>' . ($squote_values ? Cylib__squote($value) : $value) . ',';
  }
  $array_str = rtrim($array_str, ',') . ')';
  
  return $array_str;
}
?>
