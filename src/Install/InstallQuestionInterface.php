<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Install;

/**
 * Something `coolms:install` asks the installation, contributed by the module the answer belongs to -- the
 * application's base URL, for one.
 *
 * Each question is an option of the command (`--<option>`) for an install that runs unattended, and is asked when the
 * install runs interactively. Its shape is checked before anything is installed, so a malformed answer refuses with
 * nothing done; it is applied after the installers have run, because what makes an answer acceptable (for the base
 * URL, a host a section serves) may only exist then. An empty answer skips it: the setting stays unset, and the
 * module's own health row says so.
 *
 * !! **Never for a secret.** The answer is echoed back, and as an option it stands on the command line, where the
 * shell's history and the process list keep it.
 *
 * Tagged `coolms.install_question` by the bundle's autoconfiguration; implement the interface, nothing more.
 */
interface InstallQuestionInterface
{
    /** The command option's name, without the dashes: `base-url`. Unique among the questions and the command's own. */
    public function option(): string;

    /** The question asked when the install runs interactively, in a sentence. */
    public function question(): string;

    /** The option's help text: what it sets, and what an empty answer means. */
    public function description(): string;

    /** How to set it after the install, a command to run, e.g. `coolms:application:base-url https://host`. */
    public function setLater(): string;

    /** The installation's current answer, or null when it has none; one already answered is not asked again. */
    public function current(): ?string;

    /** Why an answer is refused on its shape alone, before anything is installed; null when its shape is acceptable. */
    public function shapeRefusal(string $answer): ?string;

    /**
     * Keep the answer. Answers the reason it was refused (for the base URL, a host no section serves), in a sentence,
     * instead of throwing; null when it was kept.
     */
    public function apply(string $answer): ?string;
}
