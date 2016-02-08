<?php

namespace Terminus\Commands;

use Terminus;
use Terminus\Dispatcher;
use Terminus\Utils;
use Terminus\Commands\TerminusCommand;
use Terminus\Dispatcher\CompositeCommand;
use Terminus\Dispatcher\RootCommand;

/**
 * @command help
 */
class HelpCommand extends TerminusCommand {
  private $recursive;

  /**
   * Get help on a certain command.
   *
   * [<commands>...]
   * : The command you want information on
   *
   * [--recursive]
   * : Display full information on all subcommands and their subcommands
   *
   * ## EXAMPLES
   *
   *     # get help for `sites` command
   *     terminus help sites
   *
   *     # get help for `sites` command and all its subcommands
   *     terminus help sites --recursive
   *
   *     # get help for `sites list` subcommand
   *     terminus help sites list
   */
  public function __invoke($args, $assoc_args) {
    $this->recursive = $this->input()->optional(
      array(
        'key' => 'recursive',
        'choices' => $assoc_args,
        'default' => false
      )
    );
    $command         = $this->findSubcommand($args);

    if ($command) {
      $status = $this->showHelp($command);
      exit($status);
    }

    $this->failure(
      '"{cmd}" is not a registered command.',
      array('cmd' => $args[0])
    );
  }

  /**
   * Finds a subcommand listed in the arguments, else returns the root command
   *
   * @param array $args Given command-line arguments
   * @return mixed
   */
  private function findSubcommand($args) {
    $command = Terminus::getRootCommand();

    while (!empty($args) && $command && $command->canHaveSubcommands()) {
      $command = $command->findSubcommand($args);
    }

    return $command;
  }

  /**
   * Retrieves the synopsis of a given command or subcommand
   *
   * @param mixed $command The command or subcommand to get documentation on
   * @return array
   */
  private function getMarkdown($command) {
    $name = implode(' ', Dispatcher\getPath($command));

    $binding = array(
      'name'        => $name,
      'shortdesc'   => $command->getShortdesc(),
      'synopsis'    => $command->getSynopsis(),
      'subcommands' => null,
      'options'     => $this->getOptions($command),
    );

    if ($command->canHaveSubcommands()) {
      $binding['subcommands'] =
        $this->getSubcommands($command);
    }
    return $binding;
  }

  /**
   * Gets the basic descriptions of a command's paramters and options from docs
   *
   * @param CompositeCommand $command The command of which to get options
   * @return array
   */
  private function getOptions(CompositeCommand $command) {
    $longdesc = $command->getLongdesc();
    $synopses = explode(
      ' ',
      str_replace(array('[', ']'), '', $command->getSynopsis())
    );
    $options  = array();
    if (is_string($longdesc)) {
      $options_list = explode("\n\n", $longdesc);
      foreach ($options_list as $option) {
        $drilldown = explode("\n", $option);
        if (count($drilldown) > 1) {
          $key       = str_replace(array('[', ']'), '', $drilldown[0]);
          if (!in_array($key, $synopses)) {
            continue;
          }
          $value     = str_replace(
            array(': ', "\n"),
            array('', ' '),
            $drilldown[1]
          );
          $options[$key] = $value;
        }
      }
    } elseif (isset($longdesc['parameters'])) {
      foreach ($longdesc['parameters'] as $parameter) {
        $options[$parameter['synopsis']] = $parameter['desc'];
      }
    }
    if (empty($options)) {
      return false;
    }
    return $options;
  }

  /**
   * Gets the basic descriptions of a command's subcommands from internal docs
   *
   * @param CompositeCommand $command The command of which to get subcommands
   * @return string[] $subcommands An array of stringified
   *   subcommands of the command
   */
  private function getSubcommands($command) {
    $subcommands = array();
    foreach ($command->getSubcommands() as $subcommand) {
      if ($this->recursive) {
        $subcommands[$subcommand->getName()] = $this->getMarkdown($subcommand);
      } else {
        $subcommands[$subcommand->getName()] = $subcommand->getShortdesc();
      }
    }
    return $subcommands;
  }

  /**
   * Displays the output with Less
   *
   * @param string $out Help text to be displayed
   * @return int Exit status of Less
   */
  private function passThroughPager($out) {
    if (Utils\isWindows()) {
      //No paging for Windows cmd.exe. Sorry!
      $this->output()->outputValue($out);
    }

    // Convert string to file handle
    $fd = fopen('php://temp', 'r+;');
    fputs($fd, $out);
    rewind($fd);

    $descriptorspec = array(
      0 => $fd,
      1 => STDOUT,
      2 => STDERR
    );

    $exit_status = proc_close(proc_open('less ', $descriptorspec, $pipes));
    return $exit_status;
  }

  /**
   * Takes a command to get help for and processes its internal documentation
   *
   * @param CompositeCommand $command The command to offer help for
   * @return int
   */
  private function showHelp(CompositeCommand $command) {
    $out         = $this->getMarkdown($command);
    $exit_status = 0;

    if ($this->log()->getOptions('logFormat') == 'json') {
      $this->output()->outputRecord($out);
    } else {
      $rendered_help = $this->helpers->template->render(
        [
          'template_name' => 'man.twig',
          'data'          => $out,
          'options'       => ['recursive' => $this->recursive]
         ]
      );
      if ($this->log()->getOptions('logFormat') == 'normal') {
        $exit_status = $this->passThroughPager($rendered_help);
      } else {
        $this->output()->outputRecord($rendered_help);
      }
    }
    return $exit_status;
  }

}

