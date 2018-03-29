<?php
namespace Psalm\Provider;

use PhpParser;
use Psalm\Checker\ProjectChecker;
use Psalm\LanguageServer\NodeVisitor\{ColumnCalculator, ReferencesAdder};

class StatementsProvider
{
    /**
     * @var FileProvider
     */
    private $file_provider;

    /**
     * @var ParserCacheProvider
     */
    private $cache_provider;

    /**
     * @var int
     */
    private $this_modified_time;

    /**
     * @var FileStorageCacheProvider
     */
    private $file_storage_cache_provider;

    /**
     * @var PhpParser\Parser|null
     */
    protected static $parser;

    public function __construct(
        FileProvider $file_provider,
        ParserCacheProvider $cache_provider,
        FileStorageCacheProvider $file_storage_cache_provider
    ) {
        $this->file_provider = $file_provider;
        $this->cache_provider = $cache_provider;
        $this->this_modified_time = filemtime(__FILE__);
        $this->file_storage_cache_provider = $file_storage_cache_provider;
    }

    /**
     * @param  string  $file_path
     * @param  bool    $debug_output
     * @param  bool    $server_mode
     *
     * @return array<int, \PhpParser\Node\Stmt>
     */
    public function getStatementsForFile($file_path, $debug_output = false, $server_mode = false)
    {
        $from_cache = false;

        $version = 'parsercache' . ($server_mode ? 'server' : '') . $this->this_modified_time;
        
        error_log(($server_mode ? 'server ' : 'not server ') . $file_path);

        $file_contents = $this->file_provider->getContents($file_path);
        $modified_time = $this->file_provider->getModifiedTime($file_path);

        $file_content_hash = md5($version . $file_contents);
        $file_cache_key = $this->cache_provider->getParserCacheKey($file_path, $this->cache_provider->use_igbinary);

        $stmts = $this->cache_provider->loadStatementsFromCache(
            $modified_time,
            $file_content_hash,
            $file_cache_key
        );

        if ($stmts === null) {
            if ($debug_output) {
                echo 'Parsing ' . $file_path . "\n";
            }

            $stmts = self::parseStatementsInFile($file_contents, $server_mode);
            $this->file_storage_cache_provider->removeCacheForFile($file_path);
        } else {
            $from_cache = true;
        }

        $this->cache_provider->saveStatementsToCache($file_cache_key, $file_content_hash, $stmts, $from_cache);

        if (!$stmts) {
            return [];
        }

        return $stmts;
    }

    /**
     * @param  string  $file_contents
     * @param  bool    $server_mode
     *
     * @return array<int, \PhpParser\Node\Stmt>
     */
    private static function parseStatementsInFile($file_contents, $server_mode)
    {
        if (!self::$parser) {
            $attributes = [
                'comments', 'startLine', 'startFilePos', 'endFilePos',
            ];

            if ($server_mode) {
                $attributes[] = 'endLine';
            }

            $lexer = version_compare(PHP_VERSION, '7.0.0dev', '>=')
                ? new PhpParser\Lexer([ 'usedAttributes' => $attributes ])
                : new PhpParser\Lexer\Emulative([ 'usedAttributes' => $attributes ]);

            self::$parser = (new PhpParser\ParserFactory())->create(PhpParser\ParserFactory::PREFER_PHP7, $lexer);
        }

        $error_handler = new \PhpParser\ErrorHandler\Collecting();

        /** @var array<int, \PhpParser\Node\Stmt> */
        $stmts = self::$parser->parse($file_contents, $error_handler);

        if (!$stmts && $error_handler->hasErrors()) {
            foreach ($error_handler->getErrors() as $error) {
                throw $error;
            }
        }

        if ($server_mode) {
            $traverser = new PhpParser\NodeTraverser;

            // Add column attributes to nodes
            $traverser->addVisitor(new ColumnCalculator($file_contents));

            $traverser->traverse($stmts);
        }

        return $stmts;
    }

    /**
     * Returns the node at a specified position
     * @param array<PhpParser\Node> $stmts
     * @param \Psalm\LanguageServer\Protocol\Position $position
     * @return PhpParser\Node|null
     */
    public static function getNodeAtPosition(array $stmts, \Psalm\LanguageServer\Protocol\Position $position)
    {
        $traverser = new PhpParser\NodeTraverser;
        $finder = new \Psalm\LanguageServer\NodeVisitor\NodeAtPositionFinder($position);
        $traverser->addVisitor($finder);
        $traverser->traverse($stmts);
        return $finder->node;
    }
}
