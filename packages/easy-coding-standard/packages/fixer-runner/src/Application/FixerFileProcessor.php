<?php

declare(strict_types=1);

namespace Symplify\EasyCodingStandard\FixerRunner\Application;

use PhpCsFixer\Differ\DifferInterface;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\Tokenizer\Tokens;
use Symplify\EasyCodingStandard\Configuration\Configuration;
use Symplify\EasyCodingStandard\Console\Style\EasyCodingStandardStyle;
use Symplify\EasyCodingStandard\Contract\Application\FileProcessorInterface;
use Symplify\EasyCodingStandard\Error\ErrorAndDiffCollector;
use Symplify\EasyCodingStandard\FixerRunner\Exception\Application\FixerFailedException;
use Symplify\EasyCodingStandard\FixerRunner\Parser\FileToTokensParser;
use Symplify\EasyCodingStandard\Skipper;
use Symplify\SmartFileSystem\SmartFileInfo;
use Symplify\SmartFileSystem\SmartFileSystem;
use Throwable;

/**
 * @see \Symplify\EasyCodingStandard\Tests\Error\ErrorCollector\FixerFileProcessorTest
 */
final class FixerFileProcessor implements FileProcessorInterface
{
    /**
     * @var string[]
     */
    private $appliedFixers = [];

    /**
     * @var FixerInterface[]
     */
    private $fixers = [];

    /**
     * @var ErrorAndDiffCollector
     */
    private $errorAndDiffCollector;

    /**
     * @var Skipper
     */
    private $skipper;

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var FileToTokensParser
     */
    private $fileToTokensParser;

    /**
     * @var DifferInterface
     */
    private $differ;

    /**
     * @var EasyCodingStandardStyle
     */
    private $easyCodingStandardStyle;

    /**
     * @var SmartFileSystem
     */
    private $smartFileSystem;

    /**
     * @param FixerInterface[] $fixers
     */
    public function __construct(
        ErrorAndDiffCollector $errorAndDiffCollector,
        Configuration $configuration,
        FileToTokensParser $fileToTokensParser,
        Skipper $skipper,
        DifferInterface $differ,
        EasyCodingStandardStyle $easyCodingStandardStyle,
        SmartFileSystem $smartFileSystem,
        array $fixers = []
    ) {
        $this->errorAndDiffCollector = $errorAndDiffCollector;
        $this->skipper = $skipper;
        $this->configuration = $configuration;
        $this->fileToTokensParser = $fileToTokensParser;
        $this->differ = $differ;
        $this->fixers = $this->sortFixers($fixers);
        $this->easyCodingStandardStyle = $easyCodingStandardStyle;
        $this->smartFileSystem = $smartFileSystem;
    }

    /**
     * @return FixerInterface[]
     */
    public function getCheckers(): array
    {
        return $this->fixers;
    }

    public function processFile(SmartFileInfo $smartFileInfo): string
    {
        $tokens = $this->fileToTokensParser->parseFromFilePath($smartFileInfo->getRealPath());

        $this->appliedFixers = [];
        foreach ($this->fixers as $fixer) {
            $this->processTokensByFixer($smartFileInfo, $tokens, $fixer);
        }

        $oldContent = $smartFileInfo->getContents();
        if ($this->appliedFixers === []) {
            return $oldContent;
        }

        $diff = $this->differ->diff($oldContent, $tokens->generateCode());
        // some fixer with feature overlap can null each other
        if ($diff === '') {
            return $oldContent;
        }

        // file has changed
        $this->errorAndDiffCollector->addDiffForFileInfo($smartFileInfo, $diff, $this->appliedFixers);

        $tokenGeneratedCode = $tokens->generateCode();
        if ($this->configuration->isFixer()) {
            $this->smartFileSystem->dumpFile($smartFileInfo->getRealPath(), $tokenGeneratedCode);
        }

        Tokens::clearCache();

        return $tokenGeneratedCode;
    }

    /**
     * @param FixerInterface[] $fixers
     * @return FixerInterface[]
     */
    private function sortFixers(array $fixers): array
    {
        usort($fixers, function (FixerInterface $firstFixer, FixerInterface $secondFixer): int {
            return $secondFixer->getPriority() <=> $firstFixer->getPriority();
        });

        return $fixers;
    }

    private function processTokensByFixer(SmartFileInfo $smartFileInfo, Tokens $tokens, FixerInterface $fixer): void
    {
        if ($this->shouldSkip($smartFileInfo, $fixer, $tokens)) {
            return;
        }

        // show current fixer in --debug / -vvv
        if ($this->easyCodingStandardStyle->isDebug()) {
            $this->easyCodingStandardStyle->writeln('     [fixer] ' . get_class($fixer));
        }

        try {
            $fixer->fix($smartFileInfo, $tokens);
        } catch (Throwable $throwable) {
            throw new FixerFailedException(sprintf(
                'Fixing of "%s" file by "%s" failed: %s in file %s on line %d',
                $smartFileInfo->getRelativeFilePath(),
                get_class($fixer),
                $throwable->getMessage(),
                $throwable->getFile(),
                $throwable->getLine()
            ), $throwable->getCode(), $throwable);
        }

        if (! $tokens->isChanged()) {
            return;
        }

        $tokens->clearEmptyTokens();
        $tokens->clearChanged();

        $this->appliedFixers[] = get_class($fixer);
    }

    private function shouldSkip(SmartFileInfo $smartFileInfo, FixerInterface $fixer, Tokens $tokens): bool
    {
        if ($this->skipper->shouldSkipCheckerAndFile($fixer, $smartFileInfo)) {
            return true;
        }

        if (! $fixer->supports($smartFileInfo)) {
            return true;
        }

        return ! $fixer->isCandidate($tokens);
    }
}
