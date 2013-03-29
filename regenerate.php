<?php
/**
 * This script regenerates the metadata file.
 */

require_once('provider.class.php');
require_once('metadata.class.php');
require_once('request.php');

try {
  print "Beginning regeneration: " . date('Y-m-j H:i') . "\n";
  $meta = new Metadata();
  $meta->generate();
  print "Finished regeneration\n";

}
catch (Exception $e) {
  print "Error: " . $e->getMessage() . "\n";
  print "The signed metadata file has not been replaced.\n";
}
