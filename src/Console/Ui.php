<?php

declare(strict_types=1);

namespace Ship\Console;

use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\FormBuilder;
use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * An interactive command's questions and messages, through Laravel Prompts.
 *
 * Prompts draws its own interface (arrow-key menus, inline validation, styled notes and tables)
 * when it has a real terminal: an interactive run whose input is the terminal and whose output is
 * decorated, outside native Windows, where Prompts can't put the terminal in raw mode. Everywhere
 * else (`-n`, piped input or output, Windows, tests feeding answers through Symfony's input) the
 * same calls fall back to Symfony's plain questions and messages, through Prompts' own fallback
 * hooks, so a command has one code path and CI logs get no cursor movement or raw escape codes.
 */
final class Ui
{
    /** Whether Prompts draws the interface (see the class comment). */
    public readonly bool $rich;

    private readonly SymfonyStyle $io;

    /**
     * @param ?bool $rich forces the interface either way (tests faking Prompts' terminal); null
     *        decides from the input and output
     */
    public function __construct(InputInterface $input, OutputInterface $output, ?bool $rich = null)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->rich = $rich ?? $input->isInteractive()
            && $output->isDecorated()
            && PHP_OS_FAMILY !== 'Windows'
            && !($input instanceof StreamableInputInterface && $input->getStream() !== null)
            && stream_isatty(STDIN);

        Prompt::setOutput($output);
        Prompt::interactive($input->isInteractive());
        // Sticky in Prompts: once a run falls back, the process stays on the fallback.
        Prompt::fallbackWhen(!$this->rich);
        $this->registerFallbacks();
    }

    /**
     * @param ?\Closure(string): ?string $validate returns the problem with an answer, or null
     */
    public function text(string $label, string $default = '', string $placeholder = '', string $hint = '', ?\Closure $validate = null): string
    {
        return \Laravel\Prompts\text(label: $label, placeholder: $placeholder, default: $default, required: true, validate: $validate, hint: $hint);
    }

    public function confirm(string $label, bool $default = false, string $hint = ''): bool
    {
        return \Laravel\Prompts\confirm(label: $label, default: $default, hint: $hint);
    }

    /**
     * @param array<string, string> $options key => label; the key is returned
     */
    public function select(string $label, array $options, ?string $default = null, string $hint = ''): string
    {
        $default ??= array_key_first($options) ?? throw new \LogicException('select() needs at least one option.');

        return (string) \Laravel\Prompts\select(label: $label, options: $options, default: $default, scroll: 10, hint: $hint);
    }

    /**
     * @param array<string, string> $options key => label
     * @param list<string> $default keys ticked to begin with
     * @return list<string> the ticked keys, in $options order
     */
    public function multiselect(string $label, array $options, array $default = [], string $hint = ''): array
    {
        $ticked = array_map(strval(...), \Laravel\Prompts\multiselect(label: $label, options: $options, default: $default, scroll: 10, required: false, hint: $hint));

        return array_values(array_filter(array_map(strval(...), array_keys($options)), static fn (string $key): bool => in_array($key, $ticked, true)));
    }

    public function intro(string $title): void
    {
        $this->rich ? \Laravel\Prompts\intro($title) : $this->io->title($title);
    }

    /**
     * Questions asked as steps of one form: Ctrl+U goes back to the previous step (Prompts' own
     * interface only; plain questions can't go back).
     */
    public function form(): FormBuilder
    {
        return \Laravel\Prompts\form();
    }

    public function note(string $message): void
    {
        $this->rich ? \Laravel\Prompts\note($this->wrap($message)) : $this->io->note($message);
    }

    public function info(string $message): void
    {
        $this->rich ? \Laravel\Prompts\info($this->wrap($message)) : $this->io->writeln($message);
    }

    public function warning(string $message): void
    {
        $this->rich ? \Laravel\Prompts\warning($this->wrap($message)) : $this->io->warning($message);
    }

    /**
     * A block shown as is (code, commands), indented.
     */
    public function code(string $code): void
    {
        $this->io->writeln(array_map(static fn (string $line): string => $line === '' ? '' : '    ' . $line, explode("\n", rtrim($code))));
        $this->io->newLine();
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $this->rich ? \Laravel\Prompts\table($headers, $rows) : $this->io->table($headers, $rows);
    }

    public function outro(string $message): void
    {
        $this->rich ? \Laravel\Prompts\outro($message) : $this->io->success($message);
    }

    /**
     * Prompts prints a message on one line however long it is; this breaks it at the terminal's
     * width (less Prompts' indent), keeping the message's own line breaks.
     */
    private function wrap(string $message): string
    {
        $width = max(40, min(Prompt::terminal()->cols(), 100) - 4);

        return implode("\n", array_map(
            static fn (string $line): string => wordwrap($line, $width, "\n", false),
            explode("\n", $message),
        ));
    }

    private function registerFallbacks(): void
    {
        $io = $this->io;

        TextPrompt::fallbackUsing(static fn (TextPrompt $prompt): string => (string) $io->ask(
            $prompt->label,
            $prompt->default === '' ? null : $prompt->default,
            static function (mixed $answer) use ($prompt): string {
                $answer = trim((string) $answer);
                $problem = $answer === '' && $prompt->required !== false
                    ? (is_string($prompt->required) ? $prompt->required : 'An answer is required.')
                    : ($prompt->validate instanceof \Closure ? ($prompt->validate)($answer) : null);

                if (is_string($problem)) {
                    throw new \RuntimeException($problem);
                }

                return $answer;
            },
        ));

        ConfirmPrompt::fallbackUsing(static fn (ConfirmPrompt $prompt): bool => $io->confirm($prompt->label, $prompt->default));

        // Comma-separated numbers or labels; "None" ticks nothing.
        MultiSelectPrompt::fallbackUsing(static function (MultiSelectPrompt $prompt) use ($io): array {
            /** @var array<string, string> $options */
            $options = $prompt->options;
            $labels = ['None', ...array_values($options)];
            $keys = array_map(strval(...), array_keys($options));
            /** @var list<string> $default */
            $default = $prompt->default;
            // Symfony takes a multiple-choice default as the choices' indexes.
            $defaultIndexes = array_keys(array_filter($labels, static fn (string $label, int $index): bool => $index > 0 && in_array($keys[$index - 1], $default, true), ARRAY_FILTER_USE_BOTH));

            $question = new ChoiceQuestion($prompt->label . ' (comma-separated)', $labels, $defaultIndexes === [] ? '0' : implode(',', $defaultIndexes));
            $question->setMultiselect(true);
            $question->setErrorMessage('%s is not a valid choice.');

            /** @var list<string> $answers */
            $answers = $io->askQuestion($question);

            return array_values(array_filter($keys, static fn (string $key): bool => in_array($options[$key], $answers, true)));
        });

        // A numbered list of the labels, so it shows "[1] Postgres" rather than service keys.
        SelectPrompt::fallbackUsing(static function (SelectPrompt $prompt) use ($io): string {
            /** @var array<string, string> $options */
            $options = $prompt->options;
            $labels = array_values($options);
            $keys = array_map(strval(...), array_keys($options));
            $defaultIndex = array_search((string) $prompt->default, $keys, strict: true);

            $question = new ChoiceQuestion($prompt->label, $labels, $defaultIndex === false ? 0 : $defaultIndex);
            $question->setErrorMessage('%s is not a valid choice.');

            /** @var string $answer */
            $answer = $io->askQuestion($question);
            $index = array_search($answer, $labels, strict: true);

            return $index === false ? $keys[$defaultIndex === false ? 0 : $defaultIndex] : $keys[$index];
        });
    }
}
