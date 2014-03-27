<?php

/**
 * This file is part of the TwigBridge package.
 *
 * @copyright Robert Crowe <hello@vivalacrowe.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TwigBridge\Command;

if (!defined('JSON_PRETTY_PRINT')) {
    define('JSON_PRETTY_PRINT', 128);
}

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Finder\Finder;
use Twig_Error_Loader;
use Twig_Error;
use RuntimeException;
use InvalidArgumentException;

/**
 * Artisan command to check the syntax of Twig templates.
 *
 * Adapted from the Symfony TwigBundle:
 * https://github.com/symfony/TwigBundle/blob/master/Command/LintCommand.php
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Lint extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $name = 'twig:lint';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Lints Twig templates';

    /**
     * {@inheritdoc}
     */
    public function fire()
    {
        $format = $this->option('format');

        // Check STDIN for the template
        if (ftell(STDIN) === 0) {
            // Read template in
            $template = '';

            while (!feof(STDIN)) {
                $template .= fread(STDIN, 1024);
            }

            return $this->display(array($this->validate($template)), $format);
        }

        $files   = $this->getFiles($this->argument('filename'), $this->option('file'), $this->option('directory'));
        $details = array();

        foreach ($files as $file) {

            try {
                $template = $this->getContents($file);
            } catch (Twig_Error_Loader $e) {
                throw new RuntimeException(sprintf('File or directory "%s" is not readable', $file));
            }

            $details[] = $this->validate($template, $file);
        }

        return $this->display($details, $format);
    }

    /**
     * Gets an array of files to lint.
     *
     * @param string $filename    Single file to check.
     * @param array  $files       Array of files to check.
     * @param array  $directories Array of directories to get the files from.
     *
     * @return array
     */
    protected function getFiles($filename, array $files, array $directories)
    {
        // Get files from passed in options
        $search    = $files;
        $extension = $this->laravel['twig.extension'];
        $finder    = new Finder;
        $paths     = $this->laravel['view']->getFinder()->getPaths();

        if (!empty($filename)) {
            $search[] = $filename;
        }

        if (!empty($directories)) {
            $search_directories = array();

            foreach ($directories as $directory) {
                foreach ($paths as $path) {
                    if (is_dir($path.'/'.$directory)) {
                        $search_directories[] = $path.'/'.$directory;
                    }
                }
            }

            if (!empty($search_directories)) {
                // Get those files from the search directory
                $finder->files()->in($search_directories)->name('*.'.$extension);

                foreach ($finder as $file) {
                    $search[] = $file->getRealPath();
                }
            }
        }

        // If no files passed, use the view paths
        if (empty($search)) {
            $finder->files()->in($paths)->name('*.'.$extension);

            foreach ($finder as $file) {
                $search[] = $file->getRealPath();
            }
        }

        return $search;
    }

    /**
     * Get the contents of the template.
     *
     * @param string $file
     *
     * @return string
     */
    protected function getContents($file)
    {
        return $this->laravel['twig.loader']->getSource($file);
    }

    /**
     * Validate the template.
     *
     * @param string $template Twig template.
     * @param string $file     Filename of the template.
     *
     * @return array
     */
    protected function validate($template, $file = null)
    {
        $twig = $this->laravel['twig'];

        try {
            $twig->parse($twig->tokenize($template, $file));
        } catch (Twig_Error $e) {
            return array(
                'template'  => $template,
                'file'      => $file,
                'valid'     => false,
                'exception' => $e,
            );
        }

        return array(
            'template'  => $template,
            'file'      => $file,
            'valid'     => true,
        );
    }

    /**
     * Output the results of the linting.
     *
     * @param array  $details Validation results from all linted files.
     * @param string $format  Format to output the results in. Supports text or json.
     *
     * @throws \InvalidArgumentException Thrown for an unknown format.
     *
     * @return int
     */
    protected function display(array $details, $format = 'text')
    {
        $verbose = $this->getOutput()->isVerbose();

        switch ($format) {
            case 'text':
                return $this->displayText($details, $verbose);

            case 'json':
                return $this->displayJson($details, $verbose);

            default:
                throw new InvalidArgumentException(sprintf('The format "%s" is not supported.', $format));
        }
    }

    /**
     * Output the results as text.
     *
     * @param array $details Validation results from all linted files.
     * @param bool  $verbose
     *
     * @return int
     */
    protected function displayText(array $details, $verbose = false)
    {
        $errors = 0;

        foreach ($details as $info) {
            if ($info['valid'] && $verbose) {
                $file = ($info['file']) ? ' in '.$info['file'] : '';
                $this->line('<info>OK</info>'.$file);
            } elseif (!$info['valid']) {
                $errors++;
                $this->renderException($info);
            }
        }

        // Output total number of successful files
        $success = count($details) - $errors;
        $total   = count($details);

        $this->comment(sprintf('%d/%d valid files', $success, $total));

        return min($errors, 1);
    }

    /**
     * Output the results as json.
     *
     * @param array $details Validation results from all linted files.
     * @param bool  $verbose
     *
     * @return int
     */
    protected function displayJson(array $details)
    {
        $errors = 0;

        array_walk(
            $details,
            function (&$info) use (&$errors) {
                $info['file'] = (string) $info['file'];
                unset($info['template']);

                if (!$info['valid']) {
                    $info['message'] = $info['exception']->getMessage();
                    unset($info['exception']);
                    $errors++;
                }
            }
        );

        $this->line(json_encode($details, JSON_PRETTY_PRINT));

        return min($errors, 1);
    }

    /**
     * Output the error to the console.
     *
     * @param array Details for the file that failed to be linted.
     *
     * @return void
     */
    protected function renderException(array $info)
    {
        $file      = $info['file'];
        $exception = $info['exception'];

        $line  = $exception->getTemplateLine();
        $lines = $this->getContext($info['template'], $line);

        if ($file) {
            $this->line(sprintf('<error>Fail</error> in %s (line %s)', $file, $line));
        } else {
            $this->line(sprintf('<error>Fail</error> (line %s)', $line));
        }

        foreach ($lines as $no => $code) {
            $this->line(
                sprintf(
                    "%s %-6s %s",
                    $no == $line ? '<error>>></error>' : '  ',
                    $no,
                    $code
                )
            );

            if ($no == $line) {
                $this->line(sprintf('<error>>> %s</error> ', $exception->getRawMessage()));
            }
        }
    }

    /**
     * Grabs the surrounding lines around the exception.
     *
     * @param string     $template Contents of Twig template.
     * @param string|int $line     Line where the exception occurred.
     * @param int        $context  Number of lines around the line where the exception occurred.
     *
     * @return array
     */
    protected function getContext($template, $line, $context = 3)
    {
        $lines    = explode("\n", $template);
        $position = max(0, $line - $context);
        $max      = min(count($lines), $line - 1 + $context);

        $result = array();

        while ($position < $max) {
            $result[$position + 1] = $lines[$position];
            $position++;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function getArguments()
    {
        return array(
            array(
                'filename',
                InputArgument::OPTIONAL,
                'Filename or directory to lint. If none supplied, all views will be checked.',
            ),
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function getOptions()
    {
        return array(
            array(
                'file',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Lint multiple files. Relative to the view path. Supports the dot syntax.',
            ),
            array(
                'directory',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Lint multiple directories. Relative to the view path. Does not support the dot syntax.',
            ),
            array(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Format to ouput the result in. Supports `text` or `json`.',
                'text',
            ),
        );
    }
}