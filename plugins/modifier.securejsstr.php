<?php
/**
 * string replace(string, string, string)
 * Replaces an occurence of a string with another string.
 *
 * @param mixed  $target
 * @param mixed  $search
 * @param mixed  $replace
 */
function CTEPL__securejsstr($target)
{
  return str_replace('\'', '\\\'', $target);
}
?>