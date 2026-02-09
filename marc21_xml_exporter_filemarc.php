#!/usr/bin/env php
<?php

/**
 * MARC21 to MARCXML Exporter - Command Line Tool
 * Converts MARC21 files (.mrc) to MARCXML format using PEAR File_MARC library
 */

require_once 'File/MARC.php';

class Marc21XmlExporter {
    private $filename;
    private $outputFile = null;
    private $showFields = [];
    private $separateFiles = false;
    private $compactXml = false;
    private $validateXml = false;
    private $recordCount = 0;
    
    public function __construct($filename) {
        $this->filename = $filename;
    }
    
    public function setOutputFile($outputFile) {
        $this->outputFile = $outputFile;
    }
    
    public function setFieldsToShow($fields) {
        $this->showFields = $fields;
    }
    
    public function setSeparateFiles($separate) {
        $this->separateFiles = $separate;
    }
    
    public function setCompactXml($compact) {
        $this->compactXml = $compact;
    }
    
    public function setValidateXml($validate) {
        $this->validateXml = $validate;
    }
    
    public function export() {
        try {
            if (!file_exists($this->filename)) {
                throw new Exception("File not found: {$this->filename}");
            }
            
            $marc = new File_MARC($this->filename, File_MARC::SOURCE_FILE);
            $this->recordCount = 0;
            
            if ($this->separateFiles) {
                $this->exportToSeparateFiles($marc);
            } else {
                $this->exportToSingleFile($marc);
            }
            
            echo "Export completed: {$this->recordCount} records processed\n";
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    private function exportToSingleFile($marc) {
        $output = $this->outputFile ? fopen($this->outputFile, 'w') : STDOUT;
        
        if ($this->outputFile && !$output) {
            throw new Exception("Cannot open output file: {$this->outputFile}");
        }
        
        // Write XML header
        $this->writeXmlHeader($output);
        
        // Process records
        while ($record = $marc->next()) {
            $this->recordCount++;
            $xmlFragment = $this->extractRecordXmlFromToXml($record);
            fwrite($output, $xmlFragment);
        }
        
        // Write XML footer
        $this->writeXmlFooter($output);
        
        if ($this->outputFile) {
            fclose($output);
        }
    }
    
    private function exportToSeparateFiles($marc) {
        $baseDir = $this->outputFile ? rtrim($this->outputFile, '/') : '.';
        echo "exportando a {$baseDir}".PHP_EOL; 
        if (!is_dir($baseDir)) {
            if (!mkdir($baseDir, 0755, true)) {
                throw new Exception("Cannot create output directory: {$baseDir}");
            }
        }
        
        $recordIndex = 1;
        while ($record = $marc->next()) {
            $this->recordCount++;
            $filename = $baseDir . '/record_' . str_pad($recordIndex, 6, '0', STR_PAD_LEFT) . '.xml';
            
            $output = fopen($filename, 'w');
            if (!$output) {
                echo "Warning: Cannot create file {$filename}, skipping record {$recordIndex}\n";
                $recordIndex++;
                continue;
            }
            
            $this->writeSingleRecordXml($output, $record);
            fclose($output);
            
            $recordIndex++;
        }
    }
    
    private function writeXmlHeader($output) {
        $indent = $this->compactXml ? '' : '  ';
        $newline = $this->compactXml ? '' : "\n";
        
        fwrite($output, '<?xml version="1.0" encoding="UTF-8"?>' . $newline);
        fwrite($output, '<marc:collection xmlns:marc="http://www.loc.gov/MARC21/slim"' . $newline);
        fwrite($output, $indent . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . $newline);
        fwrite($output, $indent . 'xsi:schemaLocation="http://www.loc.gov/MARC21/slim http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd">' . $newline);
        fwrite($output, $newline);
    }
    
    private function writeXmlFooter($output) {
        fwrite($output, '</marc:collection>' . "\n");
    }
    
    private function writeSingleRecordXml($output, $record) {
        // Apply field filtering if needed
        if (!empty($this->showFields)) {
            $record = $this->createFilteredRecord($record);
        }
        
        // Use File_MARC's native toXML() method for individual records
        $xmlString = utf8_encode(str_replace(['1','  ',' ','','4','',''],'',$record->toXML('UTF-8', !$this->compactXml, true)));
        //
        fwrite($output, $xmlString);
        
        if (!$this->compactXml) {
            fwrite($output, "\n");
        }
    }
    
    private function extractRecordXmlFromToXml($record) {
        // Apply field filtering by creating a filtered record if needed
        if (!empty($this->showFields)) {
            $record = $this->createFilteredRecord($record);
        }
        
        // Use File_MARC's native toXML() method
        $xmlString = $record->toXML('UTF-8', !$this->compactXml, false);
       return $xmlString; 

        // Extract just the <record> fragment from the complete XML document
        if (preg_match('/<record[^>]*>.*<\/record>/s', $xmlString, $matches)) {
            $recordXml = $matches[0];
            
            // Convert namespaces from default to marc: prefix
            $recordXml = str_replace('<record', '<marc:record', $recordXml);
            $recordXml = str_replace('</record>', '</marc:record>', $recordXml);
            $recordXml = str_replace('<leader>', '<marc:leader>', $recordXml);
            $recordXml = str_replace('</leader>', '</marc:leader>', $recordXml);
            $recordXml = str_replace('<controlfield tag="', '<marc:controlfield tag="', $recordXml);
            $recordXml = str_replace('</controlfield>', '</marc:controlfield>', $recordXml);
            $recordXml = str_replace('<datafield', '<marc:datafield', $recordXml);
            $recordXml = str_replace('</datafield>', '</marc:datafield>', $recordXml);
            $recordXml = str_replace('<subfield code="', '<marc:subfield code="', $recordXml);
            $recordXml = str_replace('</subfield>', '</marc:subfield>', $recordXml);
            
            // Add proper indentation if not compact
            if (!$this->compactXml) {
                $recordXml = "  " . $this->indentXmlFragment($recordXml);
            }
            
            return $recordXml . "\n";
        }
        
        // Fallback if regex fails
        return '';
    }
    
    private function createFilteredRecord($originalRecord) {
        // Create a new record with only the fields we want
        $filteredRecord = new File_MARC_Record();
        $filteredRecord->setLeader($originalRecord->getLeader());
        
        foreach ($originalRecord->getFields() as $field) {
            $tag = $field->getTag();
            if (in_array($tag, $this->showFields)) {
                $filteredRecord->appendField($field);
            }
        }
        
        return $filteredRecord;
    }
    
    private function indentXmlFragment($xmlFragment) {
        // Simple indentation for XML fragments
        $xmlFragment = preg_replace('/(<\/marc:subfield>)/', "$1\n    ", $xmlFragment);
        $xmlFragment = preg_replace('/(<\/marc:datafield>)/', "$1\n  ", $xmlFragment);
        $xmlFragment = preg_replace('/(<\/marc:controlfield>)/', "$1\n  ", $xmlFragment);
        $xmlFragment = preg_replace('/(<\/marc:leader>)/', "$1\n  ", $xmlFragment);
        $xmlFragment = str_replace('<marc:record', "  <marc:record", $xmlFragment);
        $xmlFragment = rtrim($xmlFragment) . "\n  ";
        
        return $xmlFragment;
    }
    

    
    public function validateXmlFile($filename) {
        if (!function_exists('simplexml_load_file')) {
            echo "Warning: SimpleXML not available, skipping validation\n";
            return true;
        }
        
        $xml = simplexml_load_file($filename);
        if ($xml === false) {
            echo "Error: Generated XML is not well-formed\n";
            return false;
        }
        
        echo "XML validation: Well-formed\n";
        return true;
    }
}

function showUsage() {
    echo "Usage: php marc21_xml_exporter_filemarc.php [options] <file.mrc>\n";
    echo "Options:\n";
    echo "  -o, --output FILE     Output file or directory (default: stdout)\n";
    echo "  -f, --fields FIELDS   Export only specific fields (comma-separated)\n";
    echo "  -s, --separate       Create separate files for each record\n";
    echo "  -c, --compact        Generate compact XML (no pretty-printing)\n";
    echo "  -v, --validate       Validate XML output (requires SimpleXML)\n";
    echo "  -h, --help           Show this help message\n";
    echo "\nExamples:\n";
    echo "  # Export to stdout (collection)\n";
    echo "  php marc21_xml_exporter_filemarc.php data.mrc\n";
    echo "  # Export to file (collection)\n";
    echo "  php marc21_xml_exporter_filemarc.php -o output.xml data.mrc\n";
    echo "  # Export only specific fields\n";
    echo "  php marc21_xml_exporter_filemarc.php -f 245,100,260 -o output.xml data.mrc\n";
    echo "  # Create separate files for each record in directory\n";
    echo "  php marc21_xml_exporter_filemarc.php -s -o records/ data.mrc\n";
    echo "  # Compact XML without indentation\n";
    echo "  php marc21_xml_exporter_filemarc.php -c -o output.xml data.mrc\n";
    echo "\nNote: This version uses File_MARC's native toXML() method for better standards compliance.\n";
}

function main() {
    global $argc, $argv;
    $options = getopt("so:f:cvh", ["separate", "output:", "fields:", "compact", "validate", "help"]);
    
    if (isset($options['h']) || isset($options['help'])) {
        showUsage();
        exit(0);
    }
    
    // Find the filename (last argument)
    $filename = $argv[$argc - 1];
    
    // Check if filename looks like an option (starts with -)
    if ($filename[0] === '-') {
        showUsage();
        exit(1);
    }
    
    if (!file_exists($filename)) {
        echo "Error: File '$filename' not found.\n";
        exit(1);
    }
    
    $exporter = new Marc21XmlExporter($filename);
    
    if (isset($options['o']) || isset($options['output'])) {
        $outputFile = $options['o'] ?? $options['output'];
        $exporter->setOutputFile($outputFile);
    }
    
    if (isset($options['f']) || isset($options['fields'])) {
        $fieldsOpt = $options['f'] ?? $options['fields'];
        $fields = array_map('trim', explode(',', $fieldsOpt));
        $exporter->setFieldsToShow($fields);
    }
    
    if (isset($options['s']) || isset($options['separate'])) {
        $exporter->setSeparateFiles(true);
    }
    
    if (isset($options['c']) || isset($options['compact'])) {
        $exporter->setCompactXml(true);
    }
    
    if (isset($options['v']) || isset($options['validate'])) {
        $exporter->setValidateXml(true);
    }
    
    $exporter->export();
    
    // Validate if requested and we have an output file
    if (($options['v'] ?? $options['validate'] ?? false) && isset($outputFile) && !($options['s'] ?? $options['separate'] ?? false)) {
        $exporter->validateXmlFile($outputFile);
    }
}

if (php_sapi_name() === 'cli') {
    main();
} else {
    echo "This script must be run from the command line.\n";
    exit(1);
}
