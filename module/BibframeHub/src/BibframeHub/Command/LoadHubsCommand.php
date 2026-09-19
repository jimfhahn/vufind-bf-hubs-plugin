<?php

namespace BibframeHub\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Loads the BIBFRAME Hubs tables from TSV files produced by
 * tools/hf-dataset/export_sql_tsv.py (which reads the Hugging Face Parquet).
 *
 *   php public/index.php bibframehub/load-hubs --dir /path/to/tsv
 *
 * Expects hubs.tsv, agents.tsv, relations.tsv (tab-separated, no header,
 * RFC 4180 quoting). Creates the tables if missing and replaces their contents.
 */
#[AsCommand(
    name: 'bibframehub/load-hubs',
    description: 'Load BIBFRAME Hub tables from the Parquet-derived TSV export'
)]
class LoadHubsCommand extends Command
{
    protected const BATCH = 2000;
    protected const NULLABLE = ['marc_key', 'lccn', 'media'];

    public function __construct(protected Connection $db, ?string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Directory containing hubs.tsv, agents.tsv, relations.tsv')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Do not truncate existing rows first')
            ->addOption('recreate', null, InputOption::VALUE_NONE, 'Drop and re-create the tables (use after schema changes)')
            ->setHelp('Creates bibframehub_hub / bibframehub_agent / bibframehub_relation and bulk-loads them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = rtrim((string)$input->getOption('dir'), '/');
        if ($dir === '' || !is_dir($dir)) {
            $output->writeln('<error>--dir must point to the TSV export directory</error>');
            return self::FAILURE;
        }
        foreach (['hubs', 'agents', 'relations'] as $f) {
            if (!is_readable("$dir/$f.tsv")) {
                $output->writeln("<error>Missing $dir/$f.tsv</error>");
                return self::FAILURE;
            }
        }

        if ($input->getOption('recreate')) {
            foreach (['bibframehub_relation', 'bibframehub_agent', 'bibframehub_hub'] as $t) {
                $this->db->executeStatement("DROP TABLE IF EXISTS $t");
            }
        }
        $this->createTables();
        if (!$input->getOption('keep') && !$input->getOption('recreate')) {
            foreach (['bibframehub_relation', 'bibframehub_agent', 'bibframehub_hub'] as $t) {
                $this->db->executeStatement("TRUNCATE TABLE $t");
            }
        }

        $t0 = microtime(true);
        $n = $this->load("$dir/hubs.tsv", 'bibframehub_hub',
            ['hub_id', 'title', 'marc_key', 'lccn', 'media', 'degree'], $output);
        $output->writeln(sprintf('hubs: %s rows (%.0fs)', number_format($n), microtime(true) - $t0));

        $n = $this->load("$dir/agents.tsv", 'bibframehub_agent', ['hub_id', 'agent_uri'], $output);
        $output->writeln(sprintf('agents: %s rows (%.0fs)', number_format($n), microtime(true) - $t0));

        $n = $this->load("$dir/relations.tsv", 'bibframehub_relation', ['source_id', 'target_id', 'rel_type'], $output);
        $output->writeln(sprintf('relations: %s rows (%.0fs)', number_format($n), microtime(true) - $t0));

        $output->writeln('<info>Done. Set [HubStore] backend = sql in BibframeHub.ini and clear bibframehub_rel_frequencies.json.</info>');
        return self::SUCCESS;
    }

    protected function createTables(): void
    {
        // UUID key columns are ascii_bin so joins are byte-exact and immune to
        // the server's default collation (MariaDB 11 defaults to uca1400).
        $this->db->executeStatement(
            'CREATE TABLE IF NOT EXISTS bibframehub_hub (
                hub_id   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
                title    VARCHAR(2000) NOT NULL,
                marc_key VARCHAR(2000) NULL,
                lccn     VARCHAR(40)   NULL,
                media    VARCHAR(255)  NULL,
                degree   INT UNSIGNED  NOT NULL DEFAULT 0,
                KEY idx_lccn (lccn),
                KEY idx_degree (degree),
                FULLTEXT KEY ft_title (title)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->db->executeStatement(
            'CREATE TABLE IF NOT EXISTS bibframehub_agent (
                hub_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                agent_uri VARCHAR(255) NOT NULL,
                PRIMARY KEY (hub_id, agent_uri)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->db->executeStatement(
            'CREATE TABLE IF NOT EXISTS bibframehub_relation (
                source_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                target_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                rel_type  VARCHAR(80) NOT NULL,
                KEY idx_source (source_id),
                KEY idx_target (target_id),
                KEY idx_type (rel_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Stream a TSV file into a table with multi-row INSERT IGNORE batches.
     */
    protected function load(string $path, string $table, array $columns, OutputInterface $output): int
    {
        $fh = fopen($path, 'r');
        if (!$fh) {
            throw new \RuntimeException("Cannot open $path");
        }
        $colList = implode(', ', $columns);
        $rowSql = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $count = 0;
        $batch = [];

        $flush = function () use (&$batch, &$count, $table, $colList, $rowSql) {
            if (!$batch) {
                return;
            }
            $sql = "INSERT IGNORE INTO $table ($colList) VALUES "
                . implode(', ', array_fill(0, count($batch), $rowSql));
            $this->db->executeStatement($sql, array_merge(...$batch));
            $count += count($batch);
            $batch = [];
        };

        $nullIdx = array_keys(array_intersect($columns, self::NULLABLE));

        while (($row = fgetcsv($fh, 0, "\t", '"', '\\')) !== false) {
            if (count($row) !== count($columns)) {
                continue;
            }
            foreach ($nullIdx as $i) {
                if ($row[$i] === '') {
                    $row[$i] = null;
                }
            }
            $batch[] = $row;
            if (count($batch) >= self::BATCH) {
                $flush();
                if ($count % 200000 === 0) {
                    $output->writeln("  $table: " . number_format($count));
                }
            }
        }
        $flush();
        fclose($fh);
        return $count;
    }
}
