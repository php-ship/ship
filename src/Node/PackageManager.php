<?php

declare(strict_types=1);

namespace Ship\Node;

/**
 * Which JavaScript package manager a project uses: npm, Yarn or pnpm. The one place that decides,
 * for `ship npm`/`yarn`/`pnpm`, `ship package-manager`, `ship config:test`, `ship up`'s reminder and
 * the production asset build (see ProductionBuildRunner).
 *
 * package.json's `packageManager` field (e.g. "pnpm@12.21.0+sha512...") decides. Without it, the
 * project's single lockfile does. With no lockfile either, nothing is decided yet: any tool may
 * create the first one, and the production build uses npm. Mixed states are problems, each fixed
 * by `ship package-manager <tool>`:
 *
 * - several tools' lockfiles and no `packageManager` to choose between them;
 * - `packageManager` names a tool whose lockfile is missing while another tool's exists (a switch
 *   that hasn't been finished, e.g. right after `ship init` chose a new tool);
 * - `packageManager` names a tool, but another tool's lockfile is left over next to its own.
 */
final class PackageManager
{
    public const TOOLS = ['npm', 'yarn', 'pnpm'];

    /**
     * The process environment variable ComposeFileBuilder's PACKAGE_MANAGER build arg reads: the
     * production asset build's tool (see buildTool()).
     */
    public const BUILD_VARIABLE = 'SHIP_PACKAGE_MANAGER';

    /**
     * Each tool's lockfiles. npm also honours npm-shrinkwrap.json.
     */
    public const LOCKFILES = [
        'npm' => ['package-lock.json', 'npm-shrinkwrap.json'],
        'yarn' => ['yarn.lock'],
        'pnpm' => ['pnpm-lock.yaml'],
    ];

    /**
     * @param ?string $declared the tool package.json's `packageManager` names, or null
     * @param list<string> $lockfileTools the tools whose lockfiles exist, in TOOLS order
     * @param array<string, string> $lockfiles tool => the lockfile of it that exists
     */
    private function __construct(
        public readonly bool $hasPackageJson,
        public readonly ?string $declared,
        public readonly ?string $declaredSpec,
        public readonly array $lockfileTools,
        public readonly array $lockfiles,
    ) {
    }

    public static function inspect(string $projectRoot): self
    {
        $packageJson = $projectRoot . '/package.json';
        $declared = null;
        $declaredSpec = null;

        if (is_file($packageJson)) {
            /** @var mixed $data */
            $data = json_decode((string) file_get_contents($packageJson), associative: true);
            $spec = is_array($data) && is_string($data['packageManager'] ?? null) ? trim($data['packageManager']) : '';

            if ($spec !== '') {
                $declaredSpec = $spec;
                $declared = explode('@', $spec, 2)[0];
            }
        }

        $lockfiles = [];
        foreach (self::LOCKFILES as $tool => $files) {
            foreach ($files as $file) {
                if (is_file($projectRoot . '/' . $file)) {
                    $lockfiles[$tool] = $file;
                    break;
                }
            }
        }

        return new self(is_file($packageJson), $declared, $declaredSpec, array_keys($lockfiles), $lockfiles);
    }

    /**
     * The tool the project uses, or null when nothing has decided yet (no `packageManager`, no
     * lockfile) or the state is mixed (see problem()).
     */
    public function tool(): ?string
    {
        if ($this->problem() !== null) {
            return null;
        }

        return $this->declared ?? ($this->lockfileTools[0] ?? null);
    }

    /**
     * The tool the production asset build installs and builds with: tool(), or npm when nothing
     * has decided yet (as before ship supported other tools). Null while there is a problem.
     */
    public function buildTool(): ?string
    {
        return $this->problem() !== null ? null : ($this->tool() ?? 'npm');
    }

    /**
     * What's wrong, as a sentence ending in the `ship package-manager` command that fixes it. Null
     * when the project is consistent.
     */
    public function problem(): ?string
    {
        if ($this->declared !== null && !in_array($this->declared, self::TOOLS, true)) {
            return sprintf(
                'package.json\'s packageManager is "%s", which ship doesn\'t support (npm, yarn or pnpm). '
                    . 'Pick one with `ship package-manager <npm|yarn|pnpm>`.',
                $this->declaredSpec,
            );
        }

        $others = array_values(array_diff($this->lockfileTools, $this->declared === null ? [] : [$this->declared]));

        if ($this->declared === null) {
            if (count($this->lockfileTools) < 2) {
                return null;
            }

            return sprintf(
                'the project has lockfiles of several package managers (%s) and no packageManager in '
                    . 'package.json to choose between them. Keep one with `ship package-manager <%s>`.',
                $this->lockfileNames($this->lockfileTools),
                implode('|', $this->lockfileTools),
            );
        }

        if ($others === []) {
            return null;
        }

        if (!in_array($this->declared, $this->lockfileTools, true)) {
            return sprintf(
                'package.json\'s packageManager is %s, but the lockfile is %s\'s (%s): switching to %s '
                    . 'hasn\'t been finished. Finish it with `ship package-manager %s`.',
                $this->declared,
                implode(' and ', $others),
                $this->lockfileNames($others),
                $this->declared,
                $this->declared,
            );
        }

        return sprintf(
            'package.json\'s packageManager is %s, but %s is left over from %s. Remove it with '
                . '`ship package-manager %s`.',
            $this->declared,
            $this->lockfileNames($others),
            implode(' and ', $others),
            $this->declared,
        );
    }

    /**
     * Why running $tool in this project would be wrong, ending in what to run instead. Null when
     * it's fine: the project uses $tool, or nothing has decided yet (the first install decides).
     */
    public function refusal(string $tool): ?string
    {
        if (!$this->hasPackageJson) {
            return null;
        }

        $problem = $this->problem();
        if ($problem !== null) {
            return ucfirst($problem);
        }

        $uses = $this->tool();

        if ($uses === null || $uses === $tool) {
            return null;
        }

        $evidence = $this->declared !== null
            ? 'package.json\'s packageManager'
            : $this->lockfileNames([$uses]);

        return sprintf(
            'This project uses %s (%s): running %s in it would leave lockfiles of two package managers. '
                . 'Use `ship %s …` instead, or switch the project to %s with `ship package-manager %s`.',
            $uses,
            $evidence,
            $tool,
            $uses,
            $tool,
            $tool,
        );
    }

    /**
     * @param list<string> $tools
     */
    private function lockfileNames(array $tools): string
    {
        return implode(', ', array_map(fn (string $tool): string => $this->lockfiles[$tool] ?? self::LOCKFILES[$tool][0], $tools));
    }
}
