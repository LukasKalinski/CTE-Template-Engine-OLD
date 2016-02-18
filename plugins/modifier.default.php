<?php
/**
 * @desc Generates alternate output when a variable is considered empty.
 * @param mixed $target
 * @param mixed $secondary_value   # The value to insert if $target is empty.
 * @param mixed $empty_value       # The value that will be treated as empty.
 * @return mixed
 */
function CTEPL__default($target, $secondary_value, $empty_value='')
{
  if(is_null($target) || $target === $empty_value)
    return $secondary_value;
  else
    return $target;
}
?>