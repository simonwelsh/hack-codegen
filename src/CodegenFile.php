<?hh // strict
/**
 * Copyright (c) 2015-present, Facebook, Inc.
 * All rights reserved.
 *
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the root directory of this source tree. An additional grant
 * of patent rights can be found in the PATENTS file in the same directory.
 */

namespace Facebook\HackCodegen;

use type Facebook\HackCodegen\_Private\Filesystem;
use namespace HH\Lib\{C, Str, Vec};

enum CodegenFileResult: int {
  NONE = 0;
  UPDATE = 1;
  CREATE = 2;
}
;

enum CodegenFileType: int {
  PHP = 0;
  HACK_DECL = 1;
  HACK_PARTIAL = 2;
  HACK_STRICT = 3;
}

/**
 * File of generated code. The file is composed by classes.
 * The file will be signed, either as autogenerated or partially generated,
 * depending on whether there are manual sections.
 */
final class CodegenFile {

  private CodegenFileType $fileType = CodegenFileType::HACK_STRICT;
  private ?string $docBlock;
  private string $fileName;
  private string $relativeFileName;
  private vec<string> $otherFileNames = vec[];
  private vec<CodegenClassBase> $classes = vec[];
  private vec<CodegenTrait> $traits = vec[];
  private vec<CodegenFunction> $functions = vec[];
  private vec<CodegenType> $beforeTypes = vec[];
  private vec<CodegenType> $afterTypes = vec[];
  private vec<CodegenConstant> $consts = vec[];
  private bool $doClobber = false;
  protected ?CodegenGeneratedFrom $generatedFrom;
  private bool $isSignedFile = true;
  private ?dict<string, vec<string>> $rekey = null;
  private bool $createOnly = false;
  private ?string $fileNamespace;
  private dict<string, ?string> $useNamespaces = dict[];
  private dict<string, ?string> $useTypes = dict[];
  private dict<string, ?string> $useConsts = dict[];
  private dict<string, ?string> $useFunctions = dict[];
  private ?string $shebang;
  private ?string $pseudoMainHeader;
  private ?string $pseudoMainFooter;

  public function __construct(
    private IHackCodegenConfig $config,
    string $file_name,
  ) {
    $root = $config->getRootDir();
    if (!Str\starts_with($file_name, '/')) {
      $this->relativeFileName = $file_name;
      $file_name = $root.'/'.$file_name;
    } else if (Str\starts_with($file_name, $root)) {
      $this->relativeFileName = Str\slice($file_name, Str\length($root) + 1);
    } else {
      $this->relativeFileName = $file_name;
    }
    $this->fileName = $file_name;
  }

  /**
   * Use this when refactoring generated code.  Say you're renaming a class, but
   * want to pull the manual code sections from the old file.  Use this.
   */
  public function addOriginalFile(string $file_name): this {
    $this->otherFileNames[] = $file_name;
    return $this;
  }

  public function addClasses(Traversable<CodegenClassBase> $classes): this {
    foreach ($classes as $class) {
      $this->addClass($class);
    }
    return $this;
  }

  public function addClass(CodegenClassBase $class): this {
    $this->classes[] = $class;
    return $this;
  }

  public function addConstants(Traversable<CodegenConstant> $constants): this {
    $this->consts = Vec\concat($this->consts, $constants);
    return $this;
  }

  public function addConstant(CodegenConstant $const): this {
    $this->consts[] = $const;
    return $this;
  }

  public function getClasses(): vec<CodegenClassBase> {
    return $this->classes;
  }

  public function addTrait(CodegenTrait $trait): this {
    $this->traits[] = $trait;
    return $this;
  }

  public function addFunctions(Traversable<CodegenFunction> $functions): this {
    foreach ($functions as $function) {
      $this->addFunction($function);
    }
    return $this;
  }

  public function addFunction(CodegenFunction $function): this {
    $this->functions[] = $function;
    return $this;
  }

  public function getFunctions(): vec<CodegenFunction> {
    return $this->functions;
  }

  public function addBeforeTypes(Traversable<CodegenType> $types): this {
    foreach ($types as $type) {
      $this->addBeforeType($type);
    }
    return $this;
  }

  public function addBeforeType(CodegenType $type): this {
    $this->beforeTypes[] = $type;
    return $this;
  }

  public function getBeforeTypes(): vec<CodegenType> {
    return $this->beforeTypes;
  }

  public function addAfterTypes(Traversable<CodegenType> $types): this {
    foreach ($types as $type) {
      $this->addAfterType($type);
    }
    return $this;
  }

  public function addAfterType(CodegenType $type): this {
    $this->afterTypes[] = $type;
    return $this;
  }

  public function getAfterTypes(): vec<CodegenType> {
    return $this->afterTypes;
  }


  /**
   * The absolute path.
   */
  public function getFileName(): string {
    return $this->fileName;
  }

  public function getRelativeFileName(): string {
    return $this->relativeFileName;
  }

  public function exists(): bool {
    return file_exists($this->fileName);
  }

  /**
   * Use this to pull manual code from a section keyed by $old_key and
   * place it in a section keyed by $new_key.
   * Note that $old_key could even be in a separate file, if you use
   * addOriginalFile.
   */
  public function rekeyManualSection(string $old_key, string $new_key): this {
    if ($this->rekey === null) {
      $this->rekey = dict[];
    }
    $rekey = $this->rekey;
    if (!C\contains_key($rekey, $new_key)) {
      $rekey[$new_key] = vec[$old_key];
    } else {
      $rekey[$new_key][] = $old_key;
    }
    $this->rekey = $rekey;
    return $this;
  }

  public function setFileType(CodegenFileType $type): this {
    $this->fileType = $type;
    return $this;
  }

  public function setDocBlock(string $comment): this {
    $this->docBlock = $comment;
    return $this;
  }

  public function setIsSignedFile(bool $value): this {
    $this->isSignedFile = $value;
    return $this;
  }

  private function getFileTypeDeclaration(): string {
    switch ($this->fileType) {
      case CodegenFileType::PHP:
        return '<?php';
      case CodegenFileType::HACK_DECL:
        return '<?hh // decl';
      case CodegenFileType::HACK_PARTIAL:
        return '<?hh';
      case CodegenFileType::HACK_STRICT:
        return '<?hh // strict';
    }
  }

  /**
   * Useful when creating scripts.
   *
   * You probably want:
   *     setShebangLine('#!/usr/bin/env hhvm')
   */
  public function setShebangLine(string $shebang): this {
    invariant(!strpbrk($shebang, "\n"), "Expected single line");
    invariant(Str\starts_with($shebang, '#!'), 'Shebang lines start with #!');
    $this->shebang = $shebang;
    return $this;
  }

  public function setShebangLinef(
    SprintfFormatString $format,
    mixed ...$args
  ): this {
    return $this->setShebangLine(vsprintf($format, $args));
  }

  /**
   * Use to execute code before declarations.
   *
   * Useful for scripts; eg:
   *     setPseudoMainHeader('require_once("vendor/autoload.php");');
   */
  public function setPseudoMainHeader(string $code): this {
    $this->pseudoMainHeader = $code;
    return $this;
  }

  public function setPseudoMainHeaderf(
    SprintfFormatString $format,
    mixed ...$args
  ): this {
    return $this->setPseudoMainHeader(vsprintf($format, $args));
  }

  /**
   * Use to execute code after declarations.
   *
   * Useful for scripts; eg:
   *     setPseudoMainFooter((new MyScript())->main($argv));
   */
  public function setPseudoMainFooter(string $code): this {
    $this->pseudoMainFooter = $code;
    return $this;
  }

  public function setPseudoMainFooterf(
    SprintfFormatString $format,
    mixed ...$args
  ): this {
    return $this->setPseudoMainFooter(vsprintf($format, $args));
  }

  private function assertNotHackStrictForExecutable(): void {
    invariant(
      $this->fileType !== CodegenFileType::HACK_STRICT,
      "Hack Strict can't be used for executables",
    );
  }

  public function render(): string {
    $builder = new HackBuilder($this->config);

    $shebang = $this->shebang;
    if ($shebang !== null) {
      $this->assertNotHackStrictForExecutable();
      $builder->addLine($shebang);
    }

    $builder->addLine($this->getFileTypeDeclaration());
    $header = $this->config->getFileHeader();
    if ($header) {
      foreach ($header as $line) {
        $builder->addInlineComment($line);
      }
    }

    $content = $this->getContent();

    $formatter = $this->config->getFormatter();

    if (!$this->isSignedFile) {
      $builder->add($content);
      $content = $builder->getCode();
      if ($formatter !== null) {
        $content = $formatter->format($content, $this->getFileName());
      }
      return $content;
    }

    $old_content = $this->loadExistingFiles();

    $doc_block = $this->docBlock;
    $gen_from = $this->generatedFrom;
    if ($gen_from !== null) {
      if ($doc_block !== null && !Str\ends_with($doc_block, "\n")) {
        $doc_block .= "\n";
      }
      $doc_block = $doc_block.$gen_from->render()."\n";
    }

    if (PartiallyGeneratedCode::containsManualSection($content)) {
      $builder->addDocBlock(
        PartiallyGeneratedSignedSource::getDocBlock($doc_block),
      );
      $builder->add($content);

      $code = $builder->getCode();
      $partial = new PartiallyGeneratedCode($code);
      if ($old_content !== null) {
        $code = $partial->merge($old_content, $this->rekey);
      } else {
        $partial->assertValidManualSections();
      }
      if ($formatter !== null) {
        $code = $formatter->format($code, $this->getFileName());
      }
      return PartiallyGeneratedSignedSource::signFile($code);

    } else {
      $builder->addDocBlock(SignedSource::getDocBlock($doc_block));
      $builder->add($content);
      $code = $builder->getCode();
      if ($formatter !== null) {
        $code = $formatter->format($code, $this->getFileName());
      }
      return SignedSource::signFile($code);
    }
  }

  /**
   * Use this to skip reading in the existing file.
   * Only use when you're sure you're okay with blowing away the previous file.
   */
  public function setDoClobber(bool $do_force): this {
    $this->doClobber = $do_force;
    return $this;
  }

  private function getContent(): string {
    $builder = (new HackBuilder($this->config));
    $builder->addLineIff(
      $this->fileNamespace !== null,
      'namespace %s;',
      $this->fileNamespace,
    );

    $get_use_statement = ($type, $ns, $as) ==> sprintf(
      'use %s %s%s;',
      $type,
      $ns,
      $as === null ? '' : ' as '.$as,
    );

    foreach ($this->useNamespaces as $ns => $as) {
      $builder->addLine($get_use_statement('namespace', $ns, $as));
    }
    foreach ($this->useTypes as $ns => $as) {
      $builder->addLine($get_use_statement('type', $ns, $as));
    }
    foreach ($this->useFunctions as $ns => $as) {
      $builder->addLine($get_use_statement('function', $ns, $as));
    }
    foreach ($this->useConsts as $ns => $as) {
      $builder->addLine($get_use_statement('const', $ns, $as));
    }

    $header = $this->pseudoMainHeader;
    if ($header !== null) {
      $this->assertNotHackStrictForExecutable();
      $builder->ensureNewLine()->add($header)->ensureNewLine();
    }

    foreach ($this->beforeTypes as $type) {
      $builder->ensureNewLine()->newLine();
      $builder->add($type->render());
    }
    foreach ($this->consts as $const) {
      $builder->ensureEmptyLine()->add($const->render());
    }
    foreach ($this->functions as $function) {
      $builder->ensureNewLine()->newLine();
      $builder->add($function->render());
    }
    foreach ($this->classes as $class) {
      $builder->ensureNewLine()->newLine();
      $builder->add($class->render());
    }

    foreach ($this->traits as $trait) {
      $builder->ensureNewLine()->newLine();
      $builder->add($trait->render());
    }

    foreach ($this->afterTypes as $type) {
      $builder->ensureNewLine()->newLine();
      $builder->add($type->render());
    }

    $footer = $this->pseudoMainFooter;
    if ($footer !== null) {
      $this->assertNotHackStrictForExecutable();
      $builder->ensureEmptyLine()->add($footer)->ensureNewLine();
    }
    return $builder->getCode();
  }

  private function loadExistingFiles(): ?string {
    $file_names = $this->otherFileNames;
    $file_names[] = $this->fileName;
    $all_content = array();
    foreach ($file_names as $file_name) {
      if (file_exists($file_name)) {
        $content = Filesystem::readFile($file_name);
        if ($content) {
          $root_dir = $this->config->getRootDir();
          $relative_path = Str\starts_with($file_name, $root_dir)
            ? Str\slice($file_name, Str\length($root_dir) + 1)
            : $file_name;

          if (!$this->doClobber) {
            if (!SignedSourceBase::isSignedByAnySigner($content)) {
              throw new CodegenFileNoSignatureException($relative_path);
            }
            if (!SignedSourceBase::hasValidSignatureFromAnySigner($content)) {
              throw new CodegenFileBadSignatureException($relative_path);
            }
          }
        }
        $all_content[] = $content;
      }
    }
    return implode('', $all_content);
  }

  public function setGeneratedFrom(CodegenGeneratedFrom $from): this {
    $this->generatedFrom = $from;
    return $this;
  }

  public function setNamespace(string $file_namespace): this {
    invariant($this->fileNamespace === null, 'namespace has already been set');
    $this->fileNamespace = $file_namespace;
    return $this;
  }

  public function useNamespace(string $ns, ?string $as = null): this {
    invariant(
      !C\contains_key($this->useNamespaces, $ns),
      '%s is already being used',
      $ns,
    );
    $this->useNamespaces[$ns] = $as;
    return $this;
  }

  public function useType(string $ns, ?string $as = null): this {
    invariant(
      !C\contains_key($this->useTypes, $ns),
      '%s is already being used',
      $ns,
    );
    $this->useTypes[$ns] = $as;

    return $this;
  }

  public function useFunction(string $ns, ?string $as = null): this {
    invariant(
      !C\contains_key($this->useFunctions, $ns),
      '%s is already being used',
      $ns,
    );
    $this->useFunctions[$ns] = $as;

    return $this;
  }

  public function useConst(string $ns, ?string $as = null): this {
    invariant(
      !C\contains_key($this->useConsts, $ns),
      '%s is already being used',
      $ns,
    );
    $this->useConsts[$ns] = $as;

    return $this;
  }

  /**
   * If called, save() will only write the file if it doesn't exist
   */
  public function createOnly(): this {
    $this->createOnly = true;
    return $this;
  }

  /**
   * Saves the generated file.
   *
   * @return CodegenFileResultType
   */
  public function save(): CodegenFileResult {
    Filesystem::createDirectory(
      substr($this->fileName, 0, strrpos($this->fileName, '/')),
      0777,
    );
    $is_creating = !file_exists($this->fileName);
    if (!$is_creating && $this->createOnly) {
      return CodegenFileResult::NONE;
    }
    $changed = Filesystem::writeFileIfChanged($this->fileName, $this->render());
    return $is_creating
      ? CodegenFileResult::CREATE
      : ($changed ? CodegenFileResult::UPDATE : CodegenFileResult::NONE);
  }
}

abstract class CodegenFileSignatureException extends \Exception {

  public function __construct(string $message, private string $fileName) {
    parent::__construct($message);
  }

  public function getFileName(): string {
    return $this->fileName;
  }
}

final class CodegenFileBadSignatureException
  extends CodegenFileSignatureException {

  public function __construct(string $file_name) {
    $message = sprintf(
      'The signature of the existing generated file \'%s\' is invalid',
      $file_name,
    );
    parent::__construct($message, $file_name);
  }
}

final class CodegenFileNoSignatureException
  extends CodegenFileSignatureException {

  public function __construct(string $file_name) {
    $message = sprintf(
      'The existing generated file \'%s\' does not have a signature',
      $file_name,
    );
    parent::__construct($message, $file_name);
  }
}
