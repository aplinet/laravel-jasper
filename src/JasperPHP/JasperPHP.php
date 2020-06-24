<?php

namespace JasperPHP;

use JasperPHP\Exceptions\JasperCompileException;
use JasperPHP\Exceptions\JasperProcessException;
use JasperPHP\Exceptions\JasperPermissionException;
use JasperPHP\Exceptions\JasperReportNotFoundException;

class JasperPHP
{
    protected $executable = null;
    protected $the_command;
    protected $redirect_output;
    protected $background;
    protected $windows = false;
    protected $formats = array('pdf', 'rtf', 'xls', 'xlsx', 'docx', 'odt', 'ods', 'pptx', 'csv', 'html', 'xhtml', 'xml', 'jrprint');
    protected $resource_directory; // Path to report resource dir or jar file

    function __construct()
    {
        $this->executable = config('jasper.executable_path') ? config('jasper.executable_path') : realpath(__DIR__ . "/../JasperStarter/bin/jasperstarter");
        if (!file_exists($this->executable) && !is_executable($this->executable)) {
            throw new \Exception("JasperStarter executable not found, or is not executable (check permissions)", 1);
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->windows = true;
        }

        $resourceDir = config('jasper.resource_dir');
        if (!$resourceDir) {
            $this->resource_directory = __DIR__ . "/../../../../../";
        } else {
            if (!file_exists($resourceDir)) {
                throw new \Exception("Invalid resource directory", 1);
            }
            $this->resource_directory = $resourceDir;
        }
    }


    /**
     * Replace subreport links in file 
     * 
     * Jasper starter uses .jasper connections to subreports, while Jasper Studio saves .jrxml connections
     * Warning ! This should be rewritten to use streams..
     * 
     * @param string $filePath Full path to a file
     * @param string[] $subreportNames Array of subreport names to replace
     * @return void
     */
    public function replaceSubreportLinks($filePath, $subreportNames)
    {

        $fileContents = file_get_contents($filePath);
        foreach ($subreportNames as $subreportName) {
            $fileContents = str_replace($subreportName . '.jrxml', $subreportName . '.jasper', $fileContents);
        }

        file_put_contents(
            $filePath,
            $fileContents
        );
    }

    /**
     * Compile report and related subreports in a directory.
     * 
     * Main report has to be named index.jrxml
     *
     * @param string $reportName Report directory name relative specified resource dir (check config)
     * @param string $outputFile Output file(s) path, defaults to report dir
     * @param boolean $background
     * @param string[] $formats Array of output file formats - default ["pdf"]
     * @param boolean $redirect_output
     * @return void
     */
    public function processReport($reportName, $outputFile = false, $formats = array("pdf"), $parameters = array(), $db_connection = array(), $background = true, $redirect_output = true)
    {
        $reportDir = $this->resource_directory . '/' . $reportName;
        $resourceDir = $reportDir;

        $processResult = $this->process(
            $reportDir . '/index.jasper',
            $outputFile,
            $formats,
            $parameters,
            $db_connection,
            $background,
            $redirect_output,
            $resourceDir
        )->execute();

        if (count($processResult)) {
            throw new JasperProcessException("Could not process report ($reportName): " . json_encode($processResult, true), 1);
        }
        return $this;
    }
    /**
     * Compile report and related subreports in a directory.
     * 
     * Main report has to be named index.jrxml
     *
     * @param string $reportName Report directory name relative specified resource dir (check config)
     * @param boolean $background
     * @param boolean $redirect_output
     * @return void
     */
    public function compileReport($reportName, $background = true, $redirect_output = true)
    {
        $test = $this->resource_directory;
        $reportDir = $this->resource_directory . '/' . $reportName;
        if (!is_dir($reportDir)) {
            throw new JasperReportNotFoundException("Report directory does not exist: '" . $reportDir . "'", 1);
        }
        if (!is_writable($reportDir)) {
            throw new JasperPermissionException("Report directory is not writable: '" . $reportDir . "'", 1);
        }
        if (!file_exists($reportDir . "/index.jrxml")) {
            throw new JasperReportNotFoundException("Report index file does not exist: '" . $reportDir . "/index.jrxml" . "'", 1);
        }

        $reportFilenames = glob($reportDir . '/*.{jrxml}', GLOB_BRACE);

        foreach ($reportFilenames as $reportFilename) {
            $pathParts = pathinfo($reportFilename);
            $baseFilename = $pathParts['dirname'] . '/' . $pathParts['filename'];
            $jasperFilename = $baseFilename . '.jasper';

            // Remove compiled files
            if (file_exists($reportFilename)) {
                if (is_writable($jasperFilename)) {
                    unlink($jasperFilename);
                } else {
                    throw new JasperPermissionException("could not remove compiled file, check permissions: '" . $jasperFilename . "'", 1);
                }
            }
            // Replace jrxml links to jasper
            $this->replaceSubreportLinks($reportFilename, array_map(function ($reportFilename) {
                return pathinfo($reportFilename, PATHINFO_FILENAME);
            }, $reportFilenames));

            // Compile

            $compilationResult = $this->compile($reportFilename, $baseFilename)->execute();
            if (count($compilationResult)) {
                throw new JasperCompileException("Jasper compilation error: " . json_encode($compilationResult, true), 1);
            }
        }
        return $this;
    }
    public function compile($input_file, $output_file = false, $background = true, $redirect_output = true)
    {
        if (is_null($input_file) || empty($input_file))
            throw new \Exception("No input file", 1);

        $command =  $this->executable;

        $command .= " compile ";

        $command .= $input_file;

        if ($output_file !== false)
            $command .= " -o " . $output_file;

        $this->redirect_output  = $redirect_output;
        $this->background       = $background;
        $this->the_command      = escapeshellcmd($command);

        return $this;
    }

    public function process($input_file, $output_file = false, $format = array("pdf"), $parameters = array(), $db_connection = array(), $background = true, $redirect_output = true, $resource_dir = null)
    {
        if (is_null($input_file) || empty($input_file))
            throw new \Exception("No input file", 1);

        if (is_array($format)) {
            foreach ($format as $key) {
                if (!in_array($key, $this->formats))
                    throw new \Exception("Invalid format!", 1);
            }
        } else {
            if (!in_array($format, $this->formats))
                throw new \Exception("Invalid format!", 1);
        }

        $command = $this->executable;

        $command .= " process ";

        $command .= $input_file;

        if ($output_file !== false)
            $command .= " -o " . $output_file;

        if (is_array($format))
            $command .= " -f " . join(" ", $format);
        else
            $command .= " -f " . $format;

        // Resources dir
        if ($resource_dir) {
            $command .= " -r " . $resource_dir;
        } else {
            $command .= " -r " . $this->resource_directory;
        }

        if (count($parameters) > 0) {
            $command .= " -P";
            foreach ($parameters as $key => $value) {
                if (is_string($value))
                    $command .= " $key=\"$value\"";
                else
                    $command .= " $key=$value";
            }
        }

        if (count($db_connection) > 0) {
            $command .= " -t " . $db_connection['driver'];

            if (isset($db_connection['username']) && !empty($db_connection['username']))
                $command .= " -u " . $db_connection['username'];

            if (isset($db_connection['password']) && !empty($db_connection['password']))
                $command .= " -p " . $db_connection['password'];

            if (isset($db_connection['host']) && !empty($db_connection['host']))
                $command .= " -H " . $db_connection['host'];

            if (isset($db_connection['database']) && !empty($db_connection['database']))
                $command .= " -n " . $db_connection['database'];

            if (isset($db_connection['port']) && !empty($db_connection['port']))
                $command .= " --db-port " . $db_connection['port'];

            if (isset($db_connection['jdbc_driver']) && !empty($db_connection['jdbc_driver']))
                $command .= " --db-driver " . $db_connection['jdbc_driver'];

            if (isset($db_connection['jdbc_url']) && !empty($db_connection['jdbc_url']))
                $command .= " --db-url " . $db_connection['jdbc_url'];

            if (isset($db_connection['jdbc_dir']) && !empty($db_connection['jdbc_dir']))
                $command .= ' --jdbc-dir ' . $db_connection['jdbc_dir'];

            if (isset($db_connection['db_sid']) && !empty($db_connection['db_sid']))
                $command .= ' --db-sid ' . $db_connection['db_sid'];

            if (isset($db_connection['json_query']) && !empty($db_connection['json_query']))
                $command .= ' --json-query ' . $db_connection['json_query'];

            if (isset($db_connection['data_file']) && !empty($db_connection['data_file']))
                $command .= ' --data-file ' . $db_connection['data_file'];
        }

        $this->redirect_output  = $redirect_output;
        $this->background       = $background;
        $this->the_command      = escapeshellcmd($command);

        return $this;
    }

    public function list_parameters($input_file)
    {
        if (is_null($input_file) || empty($input_file))
            throw new \Exception("No input file", 1);

        $command = $this->executable;

        $command .= " list_parameters ";

        $command .= $input_file;

        $this->the_command = escapeshellcmd($command);

        return $this;
    }

    public function output()
    {
        return escapeshellcmd($this->the_command);
    }

    public function execute($run_as_user = false)
    {
        if ($this->redirect_output && !$this->windows)
            $this->the_command .= " 2>&1";

        if ($this->background && !$this->windows)
            $this->the_command .= " &";

        if ($run_as_user !== false && strlen($run_as_user) > 0 && !$this->windows)
            $this->the_command = "su -c \"{$this->the_command}\" {$run_as_user}";

        $output     = array();
        $return_var = 0;

        exec($this->the_command, $output, $return_var);

        if ($return_var != 0 && isset($output[0]))
            throw new \Exception($output[0], 1);
        elseif ($return_var != 0)
            throw new \Exception("Your report has an error and couldn't be processed! Try to output the command using the function `output();` and run it manually in the console.", 1);

        return $output;
    }
}
