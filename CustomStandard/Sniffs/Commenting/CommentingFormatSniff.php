<?php declare(strict_types = 1);

namespace CustomStandard\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Exceptions\TokenizerException;
use PHP_CodeSniffer\Util\Tokens;
use SlevomatCodingStandard\Helpers\CommentHelper;
use SlevomatCodingStandard\Helpers\PropertyHelper;
use SlevomatCodingStandard\Helpers\TokenHelper;

class CommentingFormatSniff implements Sniff {

    private const ERROR_MESSAGE = "This comment doesn't follow the commenting format.";
    private const COMMENTED_OUT_CODE_PERCENTAGE = 35;

    public function register() {
        return [
            T_COMMENT,
            T_DOC_COMMENT_OPEN_TAG,
        ];
    }

    public function process(File $phpcsFile, $stackPtr): void {
        $tokens = $phpcsFile->getTokens();
        $code = $tokens[$stackPtr]["code"];

        // 最初にコメントスタイルを判定する

        if ($code === T_COMMENT) {
            $this->basicCommentProcess($phpcsFile, $stackPtr);
        } elseif ($code === T_DOC_COMMENT_OPEN_TAG) {
            $this->docCommentProcess($phpcsFile, $stackPtr);
        }
    }

    private function basicCommentProcess(File $phpcsFile, int $stackPtr): void {
        $tokens = $phpcsFile->getTokens();
        $content = $tokens[$stackPtr]["content"];

        // 行コメントはブロック先頭から一度だけ処理する。
        if ($this->isSupportedLineComment($phpcsFile, $stackPtr) === true) {
            $this->lineCommentBlockProcess($phpcsFile, $stackPtr);
            return;
        }

        // 単一行コメント形式「/* */」の場合
        if (str_starts_with($content, "/*") === true && str_ends_with($content, "*/") === true) {
            $baseIndent = $this->getBaseIndent($phpcsFile, $stackPtr);
            $nextToken = $tokens[$stackPtr + 1];
            $escapeLineBreak = $nextToken["content"] !== "\n" ? "\n" . $baseIndent : "";

            preg_match("/^\/\*(.*)\*\/$/", $content, $matches);
            $body = $matches[1] ?? null;
            assert(is_string($body) === true);
            
            $trimmedBody = trim($body, " ");
            
            // 宣言構文の前にある場合はマルチラインPHPDocに変換する
            if ($this->isCommentForStructure($phpcsFile, $stackPtr) === true) {
                $formattedContent = "/**\n{$baseIndent} * {$trimmedBody}\n{$baseIndent} */" . $escapeLineBreak;
            }
            // アノテーション(@)が含まれる場合はPHPDoc形式に変換する
            elseif (str_contains($body, "@") === true) {
                $formattedContent = "/** " . $trimmedBody . " */" . $escapeLineBreak;
            } else {
                $formattedContent = "// " . $trimmedBody . $escapeLineBreak;
            }
            
            $fix = $phpcsFile->addFixableError(
                self::ERROR_MESSAGE,
                $stackPtr,
                "InvalidCommentFormat"
            );
            if ($fix === true) {
                $phpcsFile->fixer->replaceToken($stackPtr, $formattedContent);
            }

            return;
        }


        $commentBeginPtr = CommentHelper::getMultilineCommentStartPointer($phpcsFile, $stackPtr);
        if ($commentBeginPtr !== $stackPtr) {
            return;
        }

        $commentEndPtr = $this->getCommentCloseTagPtr($phpcsFile, $stackPtr + 1);
        assert(is_int($commentEndPtr) === true);

        // 複数行コメント形式の場合
        if ($commentBeginPtr < $commentEndPtr) {

            /**
             * 開始タグの前のトークンが空白の場合、インデントされているはずなのでこれを基準インデントとして設定し、
             * コメント行全体にこのインデントを挿入して整形する。
             */
            $baseIndent = $this->getBaseIndent($phpcsFile, $stackPtr);

            foreach (range($stackPtr, $commentEndPtr) as $currentPtr) {

                $currentContent = $tokens[$currentPtr]["content"];

                // コメント開始行
                if ($currentPtr === $stackPtr) {
                    if ((bool)preg_match("/^\/\*\n/", $currentContent) === false) {
                        preg_match("/^\/\*(.*)$/", $currentContent, $matches);
                        $fix = $phpcsFile->addFixableError(
                            self::ERROR_MESSAGE,
                            $currentPtr,
                            "InvalidCommentFormat"
                        );
                        if ($fix === true) {
                            $text = trim($matches[1] ?? "");
                            // 開始タグのみ、空白が含まれないので基準インデントは次の行から適用している。
                            $fixedContent = $text === "" ? "/**\n" : "/**\n{$baseIndent} * {$text}\n";
                            $phpcsFile->fixer->replaceToken($currentPtr, $fixedContent);
                        }
                    }

                    continue;
                }

                // コメント終了行
                if ($currentPtr === $commentEndPtr) {
                    if ($currentContent !== "{$baseIndent} */") {
                        $fix = $phpcsFile->addFixableError(
                            self::ERROR_MESSAGE,
                            $currentPtr,
                            "InvalidCommentFormat"
                        );
                        if ($fix === true) {
                            $text = trim($this->extractDocCommentClosingLineText($currentContent));
                            $fixedContent = $text === "" ? "{$baseIndent} */" : "{$baseIndent} * {$text}\n{$baseIndent} */";
                            $phpcsFile->fixer->replaceToken($currentPtr, $fixedContent);
                        }
                    }

                    continue;
                }

                // コメント中間行
                if ((bool)preg_match("/^{$baseIndent} \*( .*)?$/", $currentContent) === false) {
                    $body = ltrim($currentContent, " \n\r\t\v\0*");
                    $fix = $phpcsFile->addFixableError(
                        self::ERROR_MESSAGE,
                        $currentPtr,
                        "InvalidCommentFormat"
                    );
                    if ($fix === true) {
                        $phpcsFile->fixer->replaceToken($currentPtr, "{$baseIndent} * " . $body . ($body === "" ? "\n" : ""));
                    }

                    continue;
                }

                // 無意味な先頭からの空白行を除去する
                if ($currentPtr - 1 === $stackPtr && (bool)preg_match("/^{$baseIndent} \*(\s*)?$/", $currentContent) === true) {
                    $fix = $phpcsFile->addFixableError(
                        self::ERROR_MESSAGE,
                        $currentPtr,
                        "InvalidCommentFormat"
                    );
                    if ($fix === true) {
                        $phpcsFile->fixer->replaceToken($currentPtr, "");
                    }

                    continue;
                }

                // 無意味な最後の空白行を除去する
                if ($currentPtr + 1 === $commentEndPtr && (bool)preg_match("/^{$baseIndent} \*(\s*)?$/", $currentContent) === true) {
                    $fix = $phpcsFile->addFixableError(
                        self::ERROR_MESSAGE,
                        $currentPtr,
                        "InvalidCommentFormat"
                    );
                    if ($fix === true) {
                        $phpcsFile->fixer->replaceToken($currentPtr, "");
                    }
                }
            }

            // 複数行コメント形式だが本文が1行しかない場合、1行コメント形式に変換する
            if ($commentEndPtr - $stackPtr === 2) {
                $fix = $phpcsFile->addFixableError(
                    self::ERROR_MESSAGE,
                    $stackPtr + 1,
                    "InvalidCommentFormat"
                );

                if ($fix === true) {
                    $phpcsFile->fixer->beginChangeset();
                    $phpcsFile->fixer->replaceToken($stackPtr, "");
                    $phpcsFile->fixer->replaceToken($stackPtr + 1, "// " . ltrim($tokens[$stackPtr + 1]["content"], "* "));
                    $phpcsFile->fixer->replaceToken($commentEndPtr, "");
                    $phpcsFile->fixer->endChangeset();
                }

                return;
            }

            // 複数行コメントは常にPHPDoc形式に統一する
            $fix = $phpcsFile->addFixableError(
                self::ERROR_MESSAGE,
                $stackPtr,
                "InvalidCommentFormat"
            );
            if ($fix === true) {
                $phpcsFile->fixer->replaceToken($stackPtr, "/**\n");
            }
        }
    }

    private function docCommentProcess(File $phpcsFile, int $stackPtr): void {
        $tokens = $phpcsFile->getTokens();

        $closeTagPtr = $phpcsFile->findNext(T_DOC_COMMENT_CLOSE_TAG, $stackPtr + 1);

        $closeTagToken = $tokens[$closeTagPtr];
        $openTagLine = $tokens[$stackPtr]["line"];
        $closeTagLine = $closeTagToken["line"];
        $docCommentTokens = array_slice(
            array: $tokens,
            offset: $stackPtr,
            length: $closeTagPtr - $stackPtr + 1,
            preserve_keys: true
        );

        // プロパティに対するコメントの場合、コメント内に冗長なプロパティ名が含まれているかどうかをチェックする
        (function () use ($stackPtr, $docCommentTokens, $closeTagLine, $tokens, $phpcsFile): void {

            $concatenatedDocCommentContent = join(
                array_map(
                    fn(array $token): string => $token["content"],
                    $docCommentTokens
                )
            );
            // docコメントの内容に@varタグが存在しない場合はプロパティに対するコメントとみなさないので処理しない
            if (str_contains($concatenatedDocCommentContent, "@var") === false) {
                return;
            }

            $variablePtr = $phpcsFile->findNext(T_VARIABLE, $stackPtr + 1);
            $variableToken = $tokens[$variablePtr];

            // コメント終了タグの次の行にプロパティが存在する場合はプロパティに対するコメントとみなす
            if ($closeTagLine === $variableToken["line"] - 1 && PropertyHelper::isProperty($phpcsFile, $variablePtr) === true) {

                // プロパティの変数文字列
                $variableContent = $variableToken["content"];

                $docCommentVariableTokenIndex = null;
                // docコメント内の変数名が出現するインデックスを探す
                foreach ($docCommentTokens as $index => $docCommentToken) {
                    if ($docCommentToken["code"] === T_DOC_COMMENT_STRING
                        && str_contains($docCommentToken["content"], $variableContent) === true) {
                        $docCommentVariableTokenIndex = $index;
                        break;
                    }
                }

                if ($docCommentVariableTokenIndex === null) {
                    return;
                }

                $docCommentVariableContent = $docCommentTokens[$docCommentVariableTokenIndex]["content"];

                // docコメント内の変数名を除去する
                $commentStringContent = preg_replace("/\s*\\{$variableContent}/", "", $docCommentVariableContent);
                if ($commentStringContent === $docCommentVariableContent) {
                    return;
                }

                $fix = $phpcsFile->addFixableError(
                    self::ERROR_MESSAGE,
                    $stackPtr,
                    "InvalidCommentFormat"
                );
                if ($fix === true) {
                    $phpcsFile->fixer->replaceToken($docCommentVariableTokenIndex, $commentStringContent);
                }
            }
        })();

        // 1行コメント形式の場合
        if ($openTagLine === $closeTagLine) {
            $content = join(
                array_map(
                    fn(array $token): string => $token["content"],
                    $docCommentTokens
                )
            );
            $commentBody = $this->extractInlineDocCommentBody($content);

            /**
             * @の存在しないインラインPHPDoc形式はふさわしくないので、インラインコメントへの自動修正の対象とする
             * ただし、宣言構文の前にある場合はマルチラインPHPDocに変換する
             */
            if (str_contains($commentBody, "@") === false) {
                // 宣言構文の前にある場合
                if ($this->isCommentForStructure($phpcsFile, $closeTagPtr) === true) {
                    $baseIndent = $this->getBaseIndent($phpcsFile, $stackPtr);
                    $trimmedBody = trim($commentBody, " ");
                    $fix = $phpcsFile->addFixableError(
                        self::ERROR_MESSAGE,
                        $stackPtr,
                        "InvalidCommentFormat"
                    );
                    if ($fix === true) {
                        $phpcsFile->fixer->beginChangeset();
                        for ($currentPtr = $stackPtr; $currentPtr - 1 < $closeTagPtr; $currentPtr++) {
                            $phpcsFile->fixer->replaceToken($currentPtr, "");
                        }

                        $phpcsFile->fixer->replaceToken($stackPtr, "/**\n{$baseIndent} * {$trimmedBody}\n{$baseIndent} */");
                        $phpcsFile->fixer->endChangeset();
                    }

                    return;
                }
                
                $fix = $phpcsFile->addFixableError(
                    self::ERROR_MESSAGE,
                    $stackPtr,
                    "InvalidCommentFormat"
                );
                if ($fix === true) {
                    $phpcsFile->fixer->beginChangeset();
                    for ($currentPtr = $stackPtr; $currentPtr - 1 < $closeTagPtr; $currentPtr++) {
                        $phpcsFile->fixer->replaceToken($currentPtr, "");
                    }

                    $phpcsFile->fixer->replaceToken($stackPtr, "// " . trim($commentBody, " "));
                    $phpcsFile->fixer->endChangeset();
                }

                return;
            }

            if ((bool)preg_match("/^ ([^ ].+[^ ]) $/", $commentBody) === false) {
                $fix = $phpcsFile->addFixableError(
                    self::ERROR_MESSAGE,
                    $stackPtr,
                    "InvalidCommentFormat"
                );
                if ($fix === true) {
                    $phpcsFile->fixer->beginChangeset();
                    for ($currentPtr = $stackPtr; $currentPtr - 1 < $closeTagPtr; $currentPtr++) {
                        $phpcsFile->fixer->replaceToken($currentPtr, "");
                    }

                    $phpcsFile->fixer->replaceToken($stackPtr, "/** " . trim($commentBody, " ") . " */");
                    $phpcsFile->fixer->endChangeset();
                }
            }

            return;
        }

        /**
         * 開始タグの前のトークンが空白の場合、インデントされているはずなのでこれを基準インデントとして設定し、
         * コメント行全体にこのインデントを挿入して整形する。
         */
        $baseIndent = $this->getBaseIndent($phpcsFile, $stackPtr);
        $formattedDocCommentTokens = [];
        $line = "";
        $index = 0;
        foreach ($docCommentTokens as $ptr => $token) {

            $line .= $token["content"];
            $formattedDocCommentTokens[$index]["originalTokens"][] = [...$token, "pointer" => $ptr];

            if (str_ends_with($token["content"], "\n") === true) {
                $formattedDocCommentTokens[$index]["lineContent"] = $line;
                $line = "";
                $index++;
            }
        }

        $formattedDocCommentTokens[$index]["lineContent"] = $line;

        foreach ($formattedDocCommentTokens as $index => $token) {

            $currentContent = $token["lineContent"];
            $beginPtr = $token["originalTokens"][0]["pointer"];
            $endPtr = $token["originalTokens"][array_key_last($token["originalTokens"])]["pointer"];

            // コメント開始行
            if ($index === 0) {

                if ((bool)preg_match("/^\/\*\*\n/", $currentContent) === false) {
                    $text = $this->extractDocCommentOpeningLineText($currentContent);
                    $fix = $phpcsFile->addFixableError(
                        self::ERROR_MESSAGE,
                        $beginPtr,
                        "InvalidCommentFormat"
                    );
                    if ($fix === true) {
                        $text = trim($text);
                        // 開始タグのみ、空白が含まれないので基準インデントは次の行から適用している。
                        $fixedContent = (function () use ($text, $baseIndent): string {
                            if ($text === "") {
                                return "/**\n";
                            }

                            return "/**\n{$baseIndent} * {$text}\n";
                        })();

                        $this->replaceLineTokens(
                            phpcsFile: $phpcsFile,
                            beginPtr: $beginPtr,
                            endPtr: $endPtr,
                            replacement: $fixedContent
                        );
                    }

                    continue;
                }

                continue;
            }

            // コメント終了行
            if ($index === array_key_last($formattedDocCommentTokens)) {
                if ($currentContent !== "{$baseIndent} */") {
                    $fix = $phpcsFile->addFixableError(
                        self::ERROR_MESSAGE,
                        $beginPtr,
                        "InvalidCommentFormat"
                    );
                    if ($fix === true) {
                        $text = trim($this->extractDocCommentClosingLineText($currentContent));
                        $fixedContent = $text === "" ? "{$baseIndent} */" : "{$baseIndent} * {$text}\n{$baseIndent} */";
                        $this->replaceLineTokens(
                            phpcsFile: $phpcsFile,
                            beginPtr: $beginPtr,
                            endPtr: $endPtr,
                            replacement: $fixedContent
                        );
                    }
                }

                continue;
            }

            // コメント中間行
            if ((bool)preg_match("/^{$baseIndent} \*( .*)?$/", $currentContent) === false) {
                $body = ltrim($currentContent, " \n\r\t\v\0*");
                $fix = $phpcsFile->addFixableError(
                    self::ERROR_MESSAGE,
                    $beginPtr,
                    "InvalidCommentFormat"
                );
                if ($fix === true) {
                    $this->replaceLineTokens(
                        phpcsFile: $phpcsFile,
                        beginPtr: $beginPtr,
                        endPtr: $endPtr,
                        replacement: "{$baseIndent} * " . $body . ($body === "" ? "\n" : "")
                    );
                }

                continue;
            }

            // 無意味な先頭からの空白行を除去する
            if ($index - 1 === 0 && (bool)preg_match("/^{$baseIndent} \*(\s*)?$/", $currentContent) === true) {
                $fix = $phpcsFile->addFixableError(
                    self::ERROR_MESSAGE,
                    $beginPtr,
                    "InvalidCommentFormat"
                );
                if ($fix === true) {
                    $this->replaceLineTokens(
                        phpcsFile: $phpcsFile,
                        beginPtr: $beginPtr,
                        endPtr: $endPtr,
                        replacement: ""
                    );
                }

                continue;
            }

            // 無意味な最後の空白行を除去する
            if ($index + 1 === array_key_last($formattedDocCommentTokens) && (bool)preg_match("/^{$baseIndent} \*(\s*)?$/", $currentContent) === true) {
                $fix = $phpcsFile->addFixableError(
                    self::ERROR_MESSAGE,
                    $beginPtr,
                    "InvalidCommentFormat"
                );
                if ($fix === true) {
                    $this->replaceLineTokens(
                        phpcsFile: $phpcsFile,
                        beginPtr: $beginPtr,
                        endPtr: $endPtr,
                        replacement: ""
                    );
                }
            }
        }

        // 宣言構文に対するコメントの場合、インライン形式への自動変換は行わない
        if ($this->isCommentForStructure($phpcsFile, $closeTagPtr) === true) {
            return;
        }

        // 複数行コメント形式だが本文が1行しかない場合、1行コメント形式に変換する
        if (count($formattedDocCommentTokens) === 3) {
            $centerBodyToken = $formattedDocCommentTokens[1];
            $body = trim($this->extractDocCommentMiddleLineText($centerBodyToken["lineContent"]));

            $fix = $phpcsFile->addFixableError(
                self::ERROR_MESSAGE,
                $centerBodyToken["originalTokens"][0]["pointer"],
                "InvalidCommentFormat"
            );

            if ($fix === true) {
                $targetPointers = array_keys($docCommentTokens);
                $phpcsFile->fixer->beginChangeset();
                foreach ($targetPointers as $ptr) {
                    $phpcsFile->fixer->replaceToken($ptr, "");
                }

                $phpcsFile->fixer->replaceToken($targetPointers[0], "/** {$body} */");
                $phpcsFile->fixer->endChangeset();
            }
        }
    }

    /**
     * 1行分のトークンをまとめて1つの文字列に置換する(Fixer)
     *
     * PHPDoc形式の場合、トークンが行単位ではなく更に細かく分類されているので、
     * それらをまとめて1行として置換する。
     *
     * @param File $phpcsFile
     * @param non-negative-int $beginPtr 開始トークンスタックポインタ
     * @param non-negative-int $endPtr 終了トークンスタックポインタ
     * @param string $replacement 置換文字列
     *
     * @return void
     */
    private function replaceLineTokens(File $phpcsFile, int $beginPtr, int $endPtr, string $replacement): void {
        $phpcsFile->fixer->beginChangeset();
        foreach (range($beginPtr, $endPtr) as $ptr) {
            if ($ptr === $beginPtr) {
                $phpcsFile->fixer->replaceToken($ptr, $replacement);
                continue;
            }

            $phpcsFile->fixer->replaceToken($ptr, "");
        }

        $phpcsFile->fixer->endChangeset();
    }

    /**
     * インライン PHPDoc の本文を抽出する
     *
     * @param string $content
     *
     * @return string
     */
    private function extractInlineDocCommentBody(string $content): string {
        if ((bool)preg_match("/^\/\*\*(.+)\*\/$/", $content, $matches) === true) {
            return $matches[1] ?? "";
        }

        $content = preg_replace("/^\/\*\*/", "", $content) ?? $content;
        return preg_replace("/\*\/$/", "", $content) ?? $content;
    }

    /**
     * PHPDoc 開始行の本文を抽出する
     *
     * @param string $content
     *
     * @return string
     */
    private function extractDocCommentOpeningLineText(string $content): string {
        if ((bool)preg_match("/^\/\*\*(.*)$/", $content, $matches) === true) {
            return $matches[1] ?? "";
        }

        return preg_replace("/^\/\*\*/", "", $content) ?? $content;
    }

    /**
     * PHPDoc 終了行の本文を抽出する
     *
     * @param string $content
     *
     * @return string
     */
    private function extractDocCommentClosingLineText(string $content): string {
        if ((bool)preg_match("/^(.*)\*\//", $content, $matches) === true) {
            return $matches[1] ?? "";
        }

        return preg_replace("/\*\/$/", "", $content) ?? $content;
    }

    /**
     * PHPDoc 中間行の本文を抽出する
     *
     * @param string $content
     *
     * @return string
     */
    private function extractDocCommentMiddleLineText(string $content): string {
        if ((bool)preg_match("/^\h*\*(?: (.*))?\R?$/", $content, $matches) === true) {
            return $matches[1] ?? "";
        }

        return ltrim($content, " \n\r\t\v\0*");
    }

    /**
     * コメントの基準インデントを取得する
     *
     * @param File $phpcsFile
     * @param int $commentOpenTagPtr コメント開始タグのスタックポインタ
     *
     * @return string
     */
    private function getBaseIndent(File $phpcsFile, int $commentOpenTagPtr): string {
        $beforeContent = $phpcsFile->getTokens()[$commentOpenTagPtr - 1]["content"];
        return (bool)preg_match("/^ +$/", $beforeContent) === true ? $beforeContent : "";
    }

    private function isSupportedLineComment(File $phpcsFile, int $stackPtr): bool {
        $content = $phpcsFile->getTokens()[$stackPtr]["content"] ?? "";
        return CommentHelper::isLineComment($phpcsFile, $stackPtr) === true
            && (str_starts_with($content, "//") === true || str_starts_with($content, "#") === true);
    }

    /**
     * 隣接する // と # を一つの行コメントブロックとして処理する。
     */
    private function lineCommentBlockProcess(File $phpcsFile, int $stackPtr): void {
        if ($this->isLineCommentBlockStart($phpcsFile, $stackPtr) === false) {
            return;
        }

        $comments = $this->collectLineCommentBlock($phpcsFile, $stackPtr);
        if ($comments === []) {
            return;
        }

        $bodies = array_column($comments, "body");
        if ($this->isCommentedOutCode($phpcsFile, $bodies) === true) {
            $this->normalizeCommentedOutCodeBlock($phpcsFile, $comments);
            return;
        }

        if (count($comments) === 1) {
            $comment = $comments[0];
            $body = trim($comment["body"]);
            $replacement = $comment["content"];

            if ($this->isCommentForStructure($phpcsFile, $comment["pointer"]) === true) {
                $baseIndent = $this->getBaseIndent($phpcsFile, $stackPtr);
                $replacement = "/**{$phpcsFile->eolChar}{$baseIndent} * {$body}{$phpcsFile->eolChar}{$baseIndent} */{$comment["eol"]}";
            } elseif ($body !== "") {
                $replacement = "// {$body}{$comment["eol"]}";
            } else {
                $replacement = "//{$comment["eol"]}";
            }

            $this->replaceCommentTokenIfChanged($phpcsFile, $comment["pointer"], $comment["content"], $replacement);
            return;
        }

        $fixable = false;
        $replacements = [];
        foreach ($comments as $index => $comment) {
            $body = trim($comment["body"]);
            if ($index === 0) {
                $replacement = "/**{$phpcsFile->eolChar} * {$body}{$phpcsFile->eolChar}";
            } elseif ($index === array_key_last($comments)) {
                $replacement = " * {$body}{$phpcsFile->eolChar} */{$comment["eol"]}";
            } else {
                $replacement = " * {$body}{$phpcsFile->eolChar}";
            }

            $replacements[$comment["pointer"]] = $replacement;
            $fixable = $phpcsFile->addFixableError(
                self::ERROR_MESSAGE,
                $comment["pointer"],
                "InvalidCommentFormat"
            ) || $fixable;
        }

        if ($fixable === true) {
            $phpcsFile->fixer->beginChangeset();
            foreach ($replacements as $pointer => $replacement) {
                $phpcsFile->fixer->replaceToken($pointer, $replacement);
            }
            $phpcsFile->fixer->endChangeset();
        }
    }

    private function isLineCommentBlockStart(File $phpcsFile, int $stackPtr): bool {
        $tokens = $phpcsFile->getTokens();
        $currentLine = $tokens[$stackPtr]["line"];

        for ($pointer = $stackPtr - 1; $pointer >= 0; $pointer--) {
            if ($tokens[$pointer]["code"] === T_WHITESPACE) {
                if (substr_count($tokens[$pointer]["content"], $phpcsFile->eolChar) > 1) {
                    return true;
                }
                continue;
            }

            return $this->isSupportedLineComment($phpcsFile, $pointer) === false
                || $tokens[$pointer]["line"] !== $currentLine - 1;
        }

        return true;
    }

    /**
     * @return array<int, array{pointer: int, content: string, body: string, eol: string}>
     */
    private function collectLineCommentBlock(File $phpcsFile, int $stackPtr): array {
        $tokens = $phpcsFile->getTokens();
        $comments = [];
        $previousLine = $tokens[$stackPtr]["line"] - 1;

        for ($pointer = $stackPtr, $count = count($tokens); $pointer < $count; $pointer++) {
            if ($tokens[$pointer]["code"] === T_WHITESPACE) {
                if (substr_count($tokens[$pointer]["content"], $phpcsFile->eolChar) > 1) {
                    break;
                }
                continue;
            }

            if ($this->isSupportedLineComment($phpcsFile, $pointer) === false
                || $tokens[$pointer]["line"] !== $previousLine + 1
            ) {
                break;
            }

            $content = $tokens[$pointer]["content"];
            $eol = str_ends_with($content, $phpcsFile->eolChar) ? $phpcsFile->eolChar : "";
            $contentWithoutEol = $eol === "" ? $content : substr($content, 0, -strlen($eol));
            $body = str_starts_with($contentWithoutEol, "//")
                ? substr($contentWithoutEol, 2)
                : substr($contentWithoutEol, 1);

            $comments[] = [
                "pointer" => $pointer,
                "content" => $content,
                "body" => $body,
                "eol" => $eol,
            ];
            $previousLine = $tokens[$pointer]["line"];
        }

        return $comments;
    }

    /**
     * @param array<int, array{pointer: int, content: string, body: string, eol: string}> $comments
     */
    private function normalizeCommentedOutCodeBlock(File $phpcsFile, array $comments): void {
        $commonIndent = null;
        foreach ($comments as $comment) {
            if (trim($comment["body"]) === "") {
                continue;
            }

            preg_match("/^[ \t]*/", $comment["body"], $matches);
            $indentLength = strlen($matches[0] ?? "");
            $commonIndent = $commonIndent === null ? $indentLength : min($commonIndent, $indentLength);
        }
        $commonIndent ??= 0;

        $replacements = [];
        foreach ($comments as $comment) {
            $body = substr($comment["body"], min($commonIndent, strlen($comment["body"])));
            $replacement = trim($body) === ""
                ? "//{$comment["eol"]}"
                : "// {$body}{$comment["eol"]}";

            if ($replacement !== $comment["content"]) {
                $replacements[$comment["pointer"]] = $replacement;
            }
        }

        if ($replacements === []) {
            return;
        }

        $fixable = false;
        foreach (array_keys($replacements) as $pointer) {
            $fixable = $phpcsFile->addFixableError(
                self::ERROR_MESSAGE,
                $pointer,
                "InvalidCommentFormat"
            ) || $fixable;
        }

        if ($fixable === true) {
            $phpcsFile->fixer->beginChangeset();
            foreach ($replacements as $pointer => $replacement) {
                $phpcsFile->fixer->replaceToken($pointer, $replacement);
            }
            $phpcsFile->fixer->endChangeset();
        }
    }

    private function replaceCommentTokenIfChanged(
        File $phpcsFile,
        int $pointer,
        string $currentContent,
        string $replacement
    ): void {
        if ($currentContent === $replacement) {
            return;
        }

        $fix = $phpcsFile->addFixableError(
            self::ERROR_MESSAGE,
            $pointer,
            "InvalidCommentFormat"
        );
        if ($fix === true) {
            $phpcsFile->fixer->replaceToken($pointer, $replacement);
        }
    }

    /**
     * 構造 (class, interface, trait, enum) に対するコメントかどうかを判定する
     *
     * @param File $phpcsFile
     * @param int $commentCloseTagPtr コメント終了タグのスタックポインタ
     *
     * @return bool
     */
    private function isCommentForStructure(File $phpcsFile, int $commentCloseTagPtr): bool {
        $tokens = $phpcsFile->getTokens();
        $searchFrom = $commentCloseTagPtr + 1;

        while (true) {
            $foundTokenPtr = TokenHelper::findNextExcluding(
                $phpcsFile,
                [
                    T_WHITESPACE,
                    T_PUBLIC,
                    T_PROTECTED,
                    T_PRIVATE,
                    T_STATIC,
                    T_FINAL,
                    T_READONLY,
                ],
                $searchFrom
            );

            if (is_int($foundTokenPtr) === false) {
                return false;
            }

            // 属性（#[...]）はスキップして、その先のトークンを再検索する
            if ($tokens[$foundTokenPtr]["code"] === T_ATTRIBUTE) {
                $searchFrom = ($tokens[$foundTokenPtr]["attribute_closer"] ?? $foundTokenPtr) + 1;
                continue;
            }

            return in_array(
                $tokens[$foundTokenPtr]["code"] ?? [],
                [
                    T_CLASS,
                    T_INTERFACE,
                    T_TRAIT,
                    T_ENUM,
                    T_FUNCTION,
                    T_ABSTRACT,
                    T_CONST,
                ],
                true
            );
        }
    }

    /**
     * コメントの閉じタグのスタックポインタを取得する
     *
     * @param File $phpcsFile
     * @param int $beginPtr 探索を開始するトークンスタックポインタ
     *
     * @return int|null
     */
    private function getCommentCloseTagPtr(File $phpcsFile, int $beginPtr): int | null {
        $tokens = $phpcsFile->getTokens();
        for ($i = $beginPtr;; $i++) {
            $commentEndPtr = $phpcsFile->findNext(T_COMMENT, $i);
            if (is_int($commentEndPtr) === false) {
                return null;
            }

            if (str_ends_with($tokens[$commentEndPtr]["content"], "*/") === true) {
                return $commentEndPtr;
            }
        }
    }

    /**
     * コメントブロック配列からコメントアウトされたコードか判定する
     *
     * @param File $phpcsFile
     * @param string[] $bodies コメント行の本文配列
     * @return bool
     */
    private function isCommentedOutCode(File $phpcsFile, array $bodies): bool {
        $content = join("\n", $bodies);
        $content = trim($content);

        if ($content === '') {
            return false;
        }

        // Some File implementations (e.g. DummyFile) may not have tokenizerType set.
        if (isset($phpcsFile->tokenizerType) && $phpcsFile->tokenizerType === 'PHP') {
            $content = '<?php ' . $content . ' ?>';
        }

        $oldErrors = ini_get('error_reporting');
        ini_set('error_reporting', 0);
        try {
            if (isset($phpcsFile->tokenizer) === false || $phpcsFile->tokenizer === null) {
                ini_set('error_reporting', $oldErrors);
                return false;
            }

            $tokenizerClass = get_class($phpcsFile->tokenizer);
            $tokenizer = new $tokenizerClass($content, $phpcsFile->config, $phpcsFile->eolChar);
            $stringTokens = $tokenizer->getTokens();
        } catch (TokenizerException $e) {
            ini_set('error_reporting', $oldErrors);
            return false;
        }

        ini_set('error_reporting', $oldErrors);

        $numTokens = count($stringTokens);
        if ($numTokens === 0) {
            return false;
        }

        if (isset($stringTokens[0]) === false || $stringTokens[0]['code'] !== T_OPEN_TAG) {
            return false;
        } else {
            array_shift($stringTokens);
            --$numTokens;
        }

        if (isset($stringTokens[($numTokens - 1)]) === false || $stringTokens[($numTokens - 1)]['code'] !== T_CLOSE_TAG) {
            return false;
        } else {
            array_pop($stringTokens);
            --$numTokens;
        }

        if ($phpcsFile->tokenizerType === 'PHP') {
            if (isset(Tokens::$emptyTokens[$stringTokens[($numTokens - 1)]['code']]) === false) {
                return false;
            }

            if ($stringTokens[($numTokens - 1)]['code'] === T_WHITESPACE) {
                array_pop($stringTokens);
                --$numTokens;
            }
        }

        $emptyTokens = [
            T_WHITESPACE              => true,
            T_STRING                  => true,
            T_STRING_CONCAT           => true,
            T_ENCAPSED_AND_WHITESPACE => true,
            T_NONE                    => true,
            T_COMMENT                 => true,
        ];
        $emptyTokens += Tokens::$phpcsCommentTokens;

        $numCode = 0;
        $numNonWhitespace = 0;
        $numCodeKeywords = 0;
        $hasVariable = false;
        $hasStatementTerminator = false;
        $hasCallParenthesis = false;
        $hasScopeOpener = false;
        $codeKeywordTokens = [
            T_ABSTRACT,
            T_CASE,
            T_CATCH,
            T_CLASS,
            T_CONST,
            T_DO,
            T_ECHO,
            T_ELSE,
            T_ELSEIF,
            T_ENUM,
            T_FINALLY,
            T_FOR,
            T_FOREACH,
            T_FUNCTION,
            T_IF,
            T_INCLUDE,
            T_INCLUDE_ONCE,
            T_INTERFACE,
            T_NEW,
            T_PRINT,
            T_PRIVATE,
            T_PROTECTED,
            T_PUBLIC,
            T_REQUIRE,
            T_REQUIRE_ONCE,
            T_RETURN,
            T_STATIC,
            T_SWITCH,
            T_THROW,
            T_TRAIT,
            T_TRY,
            T_WHILE,
        ];
        for ($i = 0, $c = count($stringTokens); $i < $c; $i++) {
            $tokenCode = $stringTokens[$i]['code'];
            if (isset($emptyTokens[$tokenCode]) === false
                && isset(Tokens::$comparisonTokens[$tokenCode]) === false
                && isset(Tokens::$arithmeticTokens[$tokenCode]) === false
                && $tokenCode !== T_GOTO_LABEL
            ) {
                $numCode++;
            }

            if (in_array($tokenCode, $codeKeywordTokens, true)) {
                $numCodeKeywords++;
            } elseif ($tokenCode === T_VARIABLE) {
                $hasVariable = true;
            } elseif ($tokenCode === T_SEMICOLON) {
                $hasStatementTerminator = true;
            } elseif ($tokenCode === T_OPEN_PARENTHESIS) {
                $hasCallParenthesis = true;
            } elseif ($tokenCode === T_OPEN_CURLY_BRACKET) {
                $hasScopeOpener = true;
            }

            if ($tokenCode !== T_WHITESPACE) {
                ++$numNonWhitespace;
            }
        }

        if ($numNonWhitespace <= 2) {
            return false;
        }

        if ($numCodeKeywords >= 2
            || ($numCodeKeywords >= 1 && ($hasStatementTerminator || $hasCallParenthesis || $hasScopeOpener))
            || ($hasVariable && $hasStatementTerminator)
            || ($hasCallParenthesis && $hasStatementTerminator)
        ) {
            return true;
        }

        $percentCode = ceil((($numCode / count($stringTokens)) * 100));
        return $percentCode > self::COMMENTED_OUT_CODE_PERCENTAGE;
    }
}
