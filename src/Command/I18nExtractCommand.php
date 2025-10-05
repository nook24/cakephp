<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         1.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Command;

use Cake\Command\Helper\ProgressHelper;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIoInterface;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Exception\CakeException;
use Cake\Core\Plugin;
use Cake\Utility\Filesystem;
use Cake\Utility\Inflector;

/**
 * Language string extractor
 */
class I18nExtractCommand extends Command
{
    /**
     * Paths to use when looking for strings
     *
     * @var array<string>
     */
    protected array $paths = [];

    /**
     * Files from where to extract
     *
     * @var array<string>
     */
    protected array $files = [];

    /**
     * Merge all domain strings into the default.pot file
     *
     * @var bool
     */
    protected bool $merge = false;

    /**
     * Current file being processed
     *
     * @var string
     */
    protected string $file = '';

    /**
     * Contains all content waiting to be written
     *
     * @var array<string, mixed>
     */
    protected array $storage = [];

    /**
     * Extracted tokens
     *
     * @var array
     */
    protected array $tokens = [];

    /**
     * Extracted strings indexed by domain.
     *
     * @var array<string, mixed>
     */
    protected array $translations = [];

    /**
     * Destination path
     *
     * @var string
     */
    protected string $output = '';

    /**
     * An array of directories to exclude.
     *
     * @var array<string>
     */
    protected array $exclude = [];

    /**
     * Holds whether this call should extract the CakePHP Lib messages
     *
     * @var bool
     */
    protected bool $extractCore = false;

    /**
     * Displays marker error(s) if true
     *
     * @var bool
     */
    protected bool $markerError = false;

    /**
     * Count number of marker errors found
     *
     * @var int
     */
    protected int $countMarkerError = 0;

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'i18n extract';
    }

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Extract i18n POT files from application source files.';
    }

    /**
     * Method to interact with the user and get path selections.
     *
     * @param \Cake\Console\ConsoleIoInterface $io The io instance.
     * @return void
     */
    protected function getPaths(ConsoleIoInterface $io): void
    {
        $defaultPaths = array_merge(
            [APP],
            array_values(App::path('templates')),
            ['D'], // This is required to break the loop below
        );
        $defaultPathIndex = 0;
        while (true) {
            $currentPaths = $this->paths !== [] ? $this->paths : ['None'];
            $message = sprintf(
                "Current paths: %s\nWhat is the path you would like to extract?\n[Q]uit [D]one",
                implode(', ', $currentPaths),
            );
            $response = $io->ask($message, $defaultPaths[$defaultPathIndex] ?? 'D');
            if (strtoupper($response) === 'Q') {
                $io->error('Extract Aborted');
                $this->abort();
            }
            if (strtoupper($response) === 'D' && count($this->paths)) {
                $io->out();

                return;
            }
            if (strtoupper($response) === 'D') {
                $io->warning('No directories selected. Please choose a directory.');
            } elseif (is_dir($response)) {
                $this->paths[] = $response;
                $defaultPathIndex++;
            } else {
                $io->error('The directory path you supplied was not found. Please try again.');
            }
            $io->out();
        }
    }

    /**
     * Execute the command
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIoInterface $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, ConsoleIoInterface $io): ?int
    {
        $plugin = '';
        if ($args->getOption('exclude')) {
            $this->exclude = explode(',', (string)$args->getOption('exclude'));
        }
        if ($args->getOption('files')) {
            $this->files = explode(',', (string)$args->getOption('files'));
        }
        if ($args->getOption('paths')) {
            $this->paths = explode(',', (string)$args->getOption('paths'));
        }
        if ($args->getOption('plugin')) {
            $plugin = Inflector::camelize((string)$args->getOption('plugin'));
            if ($this->paths === []) {
                $this->paths = [Plugin::classPath($plugin), Plugin::templatePath($plugin)];
            }
        } elseif (!$args->getOption('paths')) {
            $this->getPaths($io);
        }

        if ($args->hasOption('extract-core')) {
            $this->extractCore = strtolower((string)$args->getOption('extract-core')) !== 'no';
        } else {
            $response = $io->askChoice(
                'Would you like to extract the messages from the CakePHP core?',
                ['y', 'n'],
                'n',
            );
            $this->extractCore = strtolower($response) === 'y';
        }

        if ($args->hasOption('exclude-plugins') && $this->isExtractingApp()) {
            $this->exclude = array_merge($this->exclude, array_values(App::path('plugins')));
        }

        if ($this->extractCore) {
            $this->paths[] = CAKE;
        }

        if ($args->hasOption('output')) {
            $this->output = (string)$args->getOption('output');
        } elseif ($args->hasOption('plugin')) {
            $this->output = Plugin::path($plugin)
                . 'resources' . DIRECTORY_SEPARATOR
                . 'locales' . DIRECTORY_SEPARATOR;
        } else {
            $message = "What is the path you would like to output?\n[Q]uit";
            $localePaths = array_values(App::path('locales'));
            if (!$localePaths) {
                $localePaths[] = ROOT . DIRECTORY_SEPARATOR
                    . 'resources' . DIRECTORY_SEPARATOR
                    . 'locales' . DIRECTORY_SEPARATOR;
            }
            while (true) {
                $response = $io->ask(
                    $message,
                    $localePaths[0],
                );
                if (strtoupper($response) === 'Q') {
                    $io->error('Extract Aborted');

                    return static::CODE_ERROR;
                }
                if ($this->isPathUsable($response)) {
                    $this->output = $response . DIRECTORY_SEPARATOR;
                    break;
                }

                $io->err('');
                $io->error(
                    'The directory path you supplied was ' .
                    'not found. Please try again.',
                );
                $io->err('');
            }
        }

        if ($args->hasOption('merge')) {
            $this->merge = strtolower((string)$args->getOption('merge')) !== 'no';
        } else {
            $io->out();
            $response = $io->askChoice(
                'Would you like to merge all domain strings into the default.pot file?',
                ['y', 'n'],
                'n',
            );
            $this->merge = strtolower($response) === 'y';
        }

        $this->markerError = (bool)$args->getOption('marker-error');

        if (!$this->files) {
            $this->searchFiles();
        }

        $this->output = rtrim($this->output, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!$this->isPathUsable($this->output)) {
            $io->error(sprintf('The output directory `%s` was not found or writable.', $this->output));

            return static::CODE_ERROR;
        }

        $this->extract($args, $io);

        return static::CODE_SUCCESS;
    }

    /**
     * Add a translation to the internal translations property
     *
     * Takes care of duplicate translations
     *
     * @param string $domain The domain
     * @param string $msgid The message string
     * @param array<string, mixed> $details Context and plural form if any, file and line references
     * @return void
     */
    protected function addTranslation(string $domain, string $msgid, array $details = []): void
    {
        $context = $details['msgctxt'] ?? '';

        if (empty($this->translations[$domain][$msgid][$context])) {
            $this->translations[$domain][$msgid][$context] = [
                'msgid_plural' => false,
            ];
        }

        if (isset($details['msgid_plural'])) {
            $this->translations[$domain][$msgid][$context]['msgid_plural'] = $details['msgid_plural'];
        }

        if (isset($details['file'])) {
            $line = $details['line'] ?? 0;
            $this->translations[$domain][$msgid][$context]['references'][$details['file']][] = $line;
        }
    }

    /**
     * Extract text
     *
     * @param \Cake\Console\Arguments $args The Arguments instance
     * @param \Cake\Console\ConsoleIoInterface $io The io instance
     * @return void
     */
    protected function extract(Arguments $args, ConsoleIoInterface $io): void
    {
        $io->out();
        $io->out();
        $io->out('Extracting...');
        $io->hr();
        $io->out('Paths:');
        foreach ($this->paths as $path) {
            $io->out('   ' . $path);
        }
        $io->out('Output Directory: ' . $this->output);
        $io->hr();
        $this->extractTokens($args, $io);
        $this->buildFiles($args);
        $this->writeFiles($args, $io);
        $this->paths = [];
        $this->files = [];
        $this->storage = [];
        $this->translations = [];
        $this->tokens = [];
        $io->out();
        if ($this->countMarkerError) {
            $io->error("{$this->countMarkerError} marker error(s) detected.");
            $io->err(' => Use the --marker-error option to display errors.');
        }

        $io->out('Done.');
    }

    /**
     * Gets the option parser instance and configures it.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to configure
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription([
            static::getDescription(),
            'Source files are parsed and string literal format strings ' .
            'provided to the <info>__</info> family of functions are extracted.',
        ])->addOption('app', [
            'help' => 'Directory where your application is located.',
        ])->addOption('paths', [
            'help' => 'Comma separated list of paths that are searched for source files.',
        ])->addOption('merge', [
            'help' => 'Merge all domain strings into a single default.po file.',
            'default' => 'no',
            'choices' => ['yes', 'no'],
        ])->addOption('output', [
            'help' => 'Full path to output directory.',
        ])->addOption('files', [
            'help' => 'Comma separated list of files to parse.',
        ])->addOption('exclude-plugins', [
            'boolean' => true,
            'default' => true,
            'help' => 'Ignores all files in plugins if this command is run inside from the same app directory.',
        ])->addOption('plugin', [
            'help' => 'Extracts tokens only from the plugin specified and '
                . "puts the result in the plugin's `locales` directory.",
            'short' => 'p',
        ])->addOption('exclude', [
            'help' => 'Comma separated list of directories to exclude.' .
                ' Any path containing a path segment with the provided values will be skipped. E.g. test,vendors',
        ])->addOption('overwrite', [
            'boolean' => true,
            'default' => false,
            'help' => 'Always overwrite existing .pot files.',
        ])->addOption('extract-core', [
            'help' => 'Extract messages from the CakePHP core libraries.',
            'choices' => ['yes', 'no'],
        ])->addOption('no-location', [
            'boolean' => true,
            'default' => false,
            'help' => 'Do not write file locations for each extracted message.',
        ])->addOption('marker-error', [
            'boolean' => true,
            'default' => false,
            'help' => 'Do not display marker error.',
        ]);

        return $parser;
    }

    /**
     * Extract tokens out of all files to be processed
     *
     * @param \Cake\Console\Arguments $args The io instance
     * @param \Cake\Console\ConsoleIoInterface $io The io instance
     * @return void
     */
    protected function extractTokens(Arguments $args, ConsoleIoInterface $io): void
    {
        $progress = $io->helper('progress');
        assert($progress instanceof ProgressHelper);
        $progress->init(['total' => count($this->files)]);
        $isVerbose = $args->getOption('verbose');

        $functions = [
            '__' => ['singular'],
            '__n' => ['singular', 'plural'],
            '__d' => ['domain', 'singular'],
            '__dn' => ['domain', 'singular', 'plural'],
            '__x' => ['context', 'singular'],
            '__xn' => ['context', 'singular', 'plural'],
            '__dx' => ['domain', 'context', 'singular'],
            '__dxn' => ['domain', 'context', 'singular', 'plural'],
        ];
        $pattern = '/(' . implode('|', array_keys($functions)) . ')\s*\(/';

        foreach ($this->files as $file) {
            $this->file = $file;
            if ($isVerbose) {
                $io->verbose(sprintf('Processing %s...', $file));
            }

            $code = (string)file_get_contents($file);

            if (preg_match($pattern, $code) === 1) {
                $allTokens = token_get_all($code);

                $this->tokens = [];
                foreach ($allTokens as $token) {
                    if (!is_array($token) || ($token[0] !== T_WHITESPACE && $token[0] !== T_INLINE_HTML)) {
                        $this->tokens[] = $token;
                    }
                }
                unset($allTokens);

                foreach ($functions as $functionName => $map) {
                    $this->parse($io, $functionName, $map);
                }
            }

            if (!$isVerbose) {
                $progress->increment(1);
                $progress->draw();
            }
        }
    }

    /**
     * Parse tokens
     *
     * @param \Cake\Console\ConsoleIoInterface $io The io instance
     * @param string $functionName Function name that indicates translatable string (e.g: '__')
     * @param array $map Array containing what variables it will find (e.g: domain, singular, plural)
     * @return void
     */
    protected function parse(ConsoleIoInterface $io, string $functionName, array $map): void
    {
        $count = 0;
        $tokenCount = count($this->tokens);

        while ($tokenCount - $count > 1) {
            $countToken = $this->tokens[$count];
            $firstParenthesis = $this->tokens[$count + 1];
            if (!is_array($countToken)) {
                $count++;
                continue;
            }

            [$type, $string, $line] = $countToken;
            if (($type === T_STRING) && ($string === $functionName) && ($firstParenthesis === '(')) {
                $position = $count;
                $depth = 0;

                while (!$depth) {
                    if ($this->tokens[$position] === '(') {
                        $depth++;
                    } elseif ($this->tokens[$position] === ')') {
                        $depth--;
                    }
                    $position++;
                }

                $mapCount = count($map);
                $strings = $this->getStrings($position, $mapCount);

                if ($mapCount === count($strings)) {
                    $singular = '';
                    $vars = array_combine($map, $strings);
                    extract($vars);
                    $domain ??= 'default';
                    $details = [
                        'file' => $this->file,
                        'line' => $line,
                    ];
                    $details['file'] = '.' . str_replace(ROOT, '', $details['file']);
                    if (isset($plural)) {
                        $details['msgid_plural'] = $plural;
                    }
                    if (isset($context)) {
                        $details['msgctxt'] = $context;
                    }
                    $this->addTranslation($domain, $singular, $details);
                } else {
                    $this->markerError($io, $this->file, $line, $functionName, $count);
                }
            }
            $count++;
        }
    }

    /**
     * Build the translate template file contents out of obtained strings
     *
     * @param \Cake\Console\Arguments $args Console arguments
     * @return void
     */
    protected function buildFiles(Arguments $args): void
    {
        $paths = $this->paths;
        $paths[] = realpath(APP) . DIRECTORY_SEPARATOR;

        usort($paths, function (string $a, string $b) {
            return strlen($a) - strlen($b);
        });

        foreach ($this->translations as $domain => $translations) {
            foreach ($translations as $msgid => $contexts) {
                foreach ($contexts as $context => $details) {
                    $plural = $details['msgid_plural'];
                    $files = $details['references'];
                    $header = '';

                    if (!$args->getOption('no-location')) {
                        $occurrences = [];
                        foreach ($files as $file => $lines) {
                            $lines = array_unique($lines);
                            foreach ($lines as $line) {
                                $occurrences[] = $file . ':' . $line;
                            }
                        }
                        $occurrences = implode("\n#: ", $occurrences);

                        $header = '#: '
                            . str_replace(DIRECTORY_SEPARATOR, '/', $occurrences)
                            . "\n";
                    }

                    $sentence = '';
                    if ($context !== '') {
                        $sentence .= "msgctxt \"{$context}\"\n";
                    }
                    if ($plural === false) {
                        $sentence .= "msgid \"{$msgid}\"\n";
                        $sentence .= "msgstr \"\"\n\n";
                    } else {
                        $sentence .= "msgid \"{$msgid}\"\n";
                        $sentence .= "msgid_plural \"{$plural}\"\n";
                        $sentence .= "msgstr[0] \"\"\n";
                        $sentence .= "msgstr[1] \"\"\n\n";
                    }

                    if ($domain !== 'default' && $this->merge) {
                        $this->store('default', $header, $sentence);
                    } else {
                        $this->store($domain, $header, $sentence);
                    }
                }
            }
        }
    }

    /**
     * Prepare a file to be stored
     *
     * @param string $domain The domain
     * @param string $header The header content.
     * @param string $sentence The sentence to store.
     * @return void
     */
    protected function store(string $domain, string $header, string $sentence): void
    {
        $this->storage[$domain] ??= [];

        if (!isset($this->storage[$domain][$sentence])) {
            $this->storage[$domain][$sentence] = $header;
        } else {
            $this->storage[$domain][$sentence] .= $header;
        }
    }

    /**
     * Write the files that need to be stored
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIoInterface $io The console io
     * @return void
     */
    protected function writeFiles(Arguments $args, ConsoleIoInterface $io): void
    {
        $io->out();
        $overwriteAll = false;
        if ($args->getOption('overwrite')) {
            $overwriteAll = true;
        }
        foreach ($this->storage as $domain => $sentences) {
            $output = $this->writeHeader($domain);
            $headerLength = strlen($output);
            foreach ($sentences as $sentence => $header) {
                $output .= $header . $sentence;
            }

            $filename = str_replace('/', '_', $domain) . '.pot';
            $outputPath = $this->output . $filename;

            if ($this->checkUnchanged($outputPath, $headerLength, $output)) {
                $io->out($filename . ' is unchanged. Skipping.');
                continue;
            }

            $response = '';
            while ($overwriteAll === false && file_exists($outputPath) && strtoupper($response) !== 'Y') {
                $io->out();
                $response = $io->askChoice(
                    sprintf('Error: %s already exists in this location. Overwrite? [Y]es, [N]o, [A]ll', $filename),
                    ['y', 'n', 'a'],
                    'y',
                );
                if (strtoupper($response) === 'N') {
                    $response = '';
                    while (!$response) {
                        $response = $io->ask('What would you like to name this file?', 'new_' . $filename);
                        $filename = $response;
                    }
                } elseif (strtoupper($response) === 'A') {
                    $overwriteAll = true;
                }
            }
            $fs = new Filesystem();
            $fs->dumpFile($this->output . $filename, $output);
        }
    }

    /**
     * Build the translation template header
     *
     * @param string $domain Domain
     * @return string Translation template header
     */
    protected function writeHeader(string $domain): string
    {
        $projectIdVersion = $domain === 'cake' ? 'CakePHP ' . Configure::version() : 'PROJECT VERSION';

        $output = "# LANGUAGE translation of CakePHP Application\n";
        $output .= "# Copyright YEAR NAME <EMAIL@ADDRESS>\n";
        $output .= "#\n";
        $output .= "#, fuzzy\n";
        $output .= "msgid \"\"\n";
        $output .= "msgstr \"\"\n";
        $output .= '"Project-Id-Version: ' . $projectIdVersion . "\\n\"\n";
        $output .= '"POT-Creation-Date: ' . date('Y-m-d H:iO') . "\\n\"\n";
        $output .= "\"PO-Revision-Date: YYYY-mm-DD HH:MM+ZZZZ\\n\"\n";
        $output .= "\"Last-Translator: NAME <EMAIL@ADDRESS>\\n\"\n";
        $output .= "\"Language-Team: LANGUAGE <EMAIL@ADDRESS>\\n\"\n";
        $output .= "\"MIME-Version: 1.0\\n\"\n";
        $output .= "\"Content-Type: text/plain; charset=utf-8\\n\"\n";
        $output .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
        $output .= "\"Plural-Forms: nplurals=INTEGER; plural=EXPRESSION;\\n\"\n\n";

        return $output;
    }

    /**
     * Check whether the old and new output are the same, thus unchanged
     *
     * Compares the sha1 hashes of the old and new file without header.
     *
     * @param string $oldFile The existing file.
     * @param int $headerLength The length of the file header in bytes.
     * @param string $newFileContent The content of the new file.
     * @return bool Whether the old and new file are unchanged.
     */
    protected function checkUnchanged(string $oldFile, int $headerLength, string $newFileContent): bool
    {
        if (!file_exists($oldFile)) {
            return false;
        }
        $oldFileContent = file_get_contents($oldFile);
        if ($oldFileContent === false) {
            throw new CakeException(sprintf('Cannot read file content of `%s`', $oldFile));
        }

        $oldChecksum = sha1(substr($oldFileContent, $headerLength));
        $newChecksum = sha1(substr($newFileContent, $headerLength));

        return $oldChecksum === $newChecksum;
    }

    /**
     * Get the strings from the position forward
     *
     * @param int $position Actual position on tokens array
     * @param int $target Number of strings to extract
     * @return array Strings extracted
     */
    protected function getStrings(int &$position, int $target): array
    {
        $strings = [];
        $count = 0;
        while (
            $count < $target
            && ($this->tokens[$position] === ','
                || $this->tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING
                || $this->tokens[$position][0] === T_LNUMBER
            )
        ) {
            $count = count($strings);
            if ($this->tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING && $this->tokens[$position + 1] === '.') {
                $string = '';
                while (
                    $this->tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING
                    || $this->tokens[$position] === '.'
                ) {
                    if ($this->tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $string .= $this->formatString($this->tokens[$position][1]);
                    }
                    $position++;
                }
                $strings[] = $string;
            } elseif ($this->tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING) {
                $strings[] = $this->formatString($this->tokens[$position][1]);
            } elseif ($this->tokens[$position][0] === T_LNUMBER) {
                $strings[] = $this->tokens[$position][1];
            }
            $position++;
        }

        return $strings;
    }

    /**
     * Format a string to be added as a translatable string
     *
     * @param string $string String to format
     * @return string Formatted string
     */
    protected function formatString(string $string): string
    {
        $quote = substr($string, 0, 1);
        $string = substr($string, 1, -1);
        if ($quote === '"') {
            $string = stripcslashes($string);
        } else {
            $string = strtr($string, ["\\'" => "'", '\\\\' => '\\']);
        }
        $string = str_replace("\r\n", "\n", $string);

        return addcslashes($string, "\0..\37\\\"");
    }

    /**
     * Indicate an invalid marker on a processed file
     *
     * @param \Cake\Console\ConsoleIoInterface $io The io instance.
     * @param string $file File where invalid marker resides
     * @param int $line Line number
     * @param string $marker Marker found
     * @param int $count Count
     * @return void
     */
    protected function markerError(ConsoleIoInterface $io, string $file, int $line, string $marker, int $count): void
    {
        if (!str_contains($this->file, CAKE_CORE_INCLUDE_PATH)) {
            $this->countMarkerError++;
        }

        if (!$this->markerError) {
            return;
        }

        $io->error(sprintf("Invalid marker content in %s:%s\n* %s(", $file, $line, $marker));
        $count += 2;
        $tokenCount = count($this->tokens);
        $parenthesis = 1;

        while (($tokenCount - $count > 0) && $parenthesis) {
            if (is_array($this->tokens[$count])) {
                $io->err($this->tokens[$count][1], 0);
            } else {
                $io->err($this->tokens[$count], 0);
                if ($this->tokens[$count] === '(') {
                    $parenthesis++;
                }

                if ($this->tokens[$count] === ')') {
                    $parenthesis--;
                }
            }
            $count++;
        }
        $io->err("\n");
    }

    /**
     * Search files that may contain translatable strings
     *
     * @return void
     */
    protected function searchFiles(): void
    {
        $pattern = false;
        if ($this->exclude) {
            $exclude = [];
            foreach ($this->exclude as $e) {
                if (DIRECTORY_SEPARATOR !== '\\' && !str_starts_with($e, DIRECTORY_SEPARATOR)) {
                    $e = DIRECTORY_SEPARATOR . $e;
                }
                $exclude[] = preg_quote($e, '/');
            }
            $pattern = '/' . implode('|', $exclude) . '/';
        }

        foreach ($this->paths as $path) {
            $path = realpath($path);
            if ($path === false) {
                continue;
            }
            $path .= DIRECTORY_SEPARATOR;
            $fs = new Filesystem();
            $files = $fs->findRecursive($path, '/\.php$/');
            $files = array_keys(iterator_to_array($files));
            sort($files);
            if ($pattern) {
                $files = preg_grep($pattern, $files, PREG_GREP_INVERT) ?: [];
                $files = array_values($files);
            }
            $this->files = array_merge($this->files, $files);
        }
        $this->files = array_unique($this->files);
    }

    /**
     * Returns whether this execution is meant to extract string only from directories in folder represented by the
     * APP constant, i.e. this task is extracting strings from same application.
     *
     * @return bool
     */
    protected function isExtractingApp(): bool
    {
        return $this->paths === [APP];
    }

    /**
     * Checks whether a given path is usable for writing.
     *
     * @param string $path Path to folder
     * @return bool true if it exists and is writable, false otherwise
     */
    protected function isPathUsable(string $path): bool
    {
        if (!is_dir($path)) {
            mkdir($path, 0777 ^ umask(), true);
        }

        return is_dir($path) && is_writable($path);
    }
}
