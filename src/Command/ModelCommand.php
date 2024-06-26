<?php

declare(strict_types=1);
/**
 * This file is part of MoChat.
 * @link     https://mo.chat
 * @document https://mochat.wiki
 * @contact  group@mo.chat
 * @license  https://github.com/mochat-cloud/mochat/blob/master/LICENSE
 */
namespace MoChat\Framework\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\ConfigAliyunAcm\ClientInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Database\Commands\ModelCommand as HyperfModelCommand;
use Hyperf\Database\Commands\ModelOption;
use Hyperf\Utils\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * 模型trait生成.
 */
#[Command]
class ModelCommand extends HyperfModelCommand
{
    public function __construct(ContainerInterface $container)
    {
        $config = $container->get(ConfigInterface::class);
        $logger = $container->get(StdoutLoggerInterface::class);
        if (interface_exists(ClientInterface::class) && $config->get('aliyun_acm.enable')) {
            $acmClient = $container->get(ClientInterface::class);
            $configAcm = $acmClient->pull();
            foreach ($configAcm as $key => $value) {
                $config->set($key, $value);
                $logger->debug(sprintf('Config [%s] is updated', $key));
            }
        }

        HyperfCommand::__construct('mcGen:model');
        $this->container = $container;
    }

    protected function configure()
    {
        parent::configure();
        $this->addOption(
            'force-others',
            'fo',
            InputOption::VALUE_OPTIONAL,
            '是否强制覆盖modelTrait、service、serviceInterface',
            false
        );
    }

    /**
     * 忽略模型重写.
     */
    protected function isIgnoreTable(string $table, ModelOption $option): bool
    {
        if (in_array($table, $option->getIgnoreTables())) {
            return true;
        }

        $prefix = $option->getPrefix();
        if (strpos($table, $prefix) === false) {
            return true;
        }

        ## 前缀忽略
        $tablePrefix = $this->config->get('databases.default.');

        return $table === $this->config->get('databases.migrations', 'migrations');
    }

    /**
     * Build the class with the given name.
     */
    protected function buildClass(string $table, string $name, ModelOption $option): string
    {
        $stub = file_get_contents(__DIR__ . '/stubs/Model.stub');

        return $this->replaceNamespace($stub, $name)
            ->replaceInheritance($stub, $option->getInheritance())
            ->replaceConnection($stub, $option->getPool())
            ->replaceUses($stub, $option->getUses())
            ->replaceClass($stub, $name)
            ->replaceTable($stub, $table);
    }

    /**
     * 模型生成重写.
     */
    protected function createModel(string $table, ModelOption $option)
    {
        ## 生成模型
        parent::createModel($table, $option);

        $table        = Str::replaceFirst($option->getPrefix(), '', $table);
        $forceService = $this->input->getOption('force-others') !== false;

        ## 生成服务契约
        $this->createServiceInterface($table, $option->getPath(), $forceService);
        ## 生成服务
        $this->createService($table, $option->getPath(), $forceService);
    }

    /**
     * 生成服务契约.
     * @param string $table 表名
     * @param string $modelPath 模型路径
     * @param bool $isForce 是否强制生成
     */
    protected function createServiceInterface(string $table, string $modelPath, bool $isForce): void
    {
        $this->call('mcGen:serviceInterface', [
            'table'        => trim($table),
            '--model-path' => $modelPath,
            '--force'      => $isForce,
        ]);
    }

    /**
     * 生成服务
     * @param string $table 表名
     * @param string $modelPath 模型路径
     * @param bool $isForce 是否强制生成
     */
    protected function createService(string $table, string $modelPath, bool $isForce): void
    {
        $this->call('mcGen:service', [
            'table'        => trim($table),
            '--model-path' => $modelPath,
            '--force'      => $isForce,
        ]);
    }
}
