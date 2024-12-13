<?php declare(strict_types = 1);

namespace CustomStandard\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\CommentHelper;
use SlevomatCodingStandard\Helpers\TokenHelper;

class CommentingFormatSniff implements Sniff {

    private const ERROR_MESSAGE = "This comment doesn't follow the commenting format.";

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

        // 単一行コメント形式「//」の場合
        if (str_starts_with($content, "//") === true) {

            if ((bool)preg_match("/^\/\/(([^ ])|( {2,}))(.*)$/", $content, $matches) === true) {
                $fix = $phpcsFile->addFixableError(
                    self::ERROR_MESSAGE,
                    $stackPtr,
                    "InvalidCommentFormat"
                );
                if ($fix === true) {
                    $phpcsFile->fixer->replaceToken($stackPtr, "// " . ($matches[2] ?? "") . $matches[4] . "\n");
                }

                return;
            }

            // 1行コメント形式が連続して複数行に出現する場合は、複数行コメント形式に変換する
            (function () use ($phpcsFile, $stackPtr, $tokens): void {

                $fixTargetTokens = [];

                for ($i = $stackPtr;; $i++) {
                    $content = $tokens[$i]["content"] ?? "";
                    $code = $tokens[$i]["code"] ?? null;
                    if ((bool)preg_match("/^ +$/", $content) === true) {
                        continue;
                    }

                    if ($code !== T_COMMENT || str_starts_with($content, "//") === false || CommentHelper::isLineComment($phpcsFile, $stackPtr) === false) {
                        break;
                    }

                    preg_match("/^\/\/(.*)$/", $content, $matches);
                    $body = trim($matches[1] ?? "");
                    $fixTargetTokens[] = [
                        "body" => $body,
                        "pointer" => $i,
                    ];
                }

                if (count($fixTargetTokens) <= 1) {
                    return;
                }

                $fix = false;
                foreach ($fixTargetTokens as $index => $token) {
                    $body = (function () use ($fixTargetTokens, $index, $token): string {
                        if ($index === 0) {
                            return "/*\n * {$token["body"]}\n";
                        }

                        if ($index === array_key_last($fixTargetTokens)) {
                            return " * {$token["body"]}\n */\n";
                        }

                        return " * {$token["body"]}\n";
                    })();
                    $fix = $phpcsFile->addFixableError(
                        self::ERROR_MESSAGE,
                        $token["pointer"],
                        "InvalidCommentFormat"
                    );
                    $fixTargetTokens[$index]["body"] = $body;
                }

                if ($fix === true) {
                    $phpcsFile->fixer->beginChangeset();
                    foreach ($fixTargetTokens as $token) {
                        $phpcsFile->fixer->replaceToken($token["pointer"], $token["body"]);
                    }

                    $phpcsFile->fixer->endChangeset();
                }

            })();

            return;
        }

        // 単一行コメント形式「#」の場合
        if (str_starts_with($content, "#") === true) {
            preg_match("/^# *(.*)$/", $content, $matches);
            $fix = $phpcsFile->addFixableError(
                self::ERROR_MESSAGE,
                $stackPtr,
                "InvalidCommentFormat"
            );
            if ($fix === true) {
                $phpcsFile->fixer->replaceToken($stackPtr, "// " . $matches[1]);
            }

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
            $formattedContent = "// " . trim($body, " ") . $escapeLineBreak;
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

            /*
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
                            $fixedContent = $text === "" ? "/*\n" : "/*\n{$baseIndent} * {$text}\n";
                            $phpcsFile->fixer->replaceToken($currentPtr, $fixedContent);
                        }
                    }

                    continue;
                }

                // コメント終了行
                if ($currentPtr === $commentEndPtr) {
                    if ($currentContent !== "{$baseIndent} */") {
                        preg_match("/^(.*)\*\//", $currentContent, $matches);
                        $fix = $phpcsFile->addFixableError(
                            self::ERROR_MESSAGE,
                            $currentPtr,
                            "InvalidCommentFormat"
                        );
                        if ($fix === true) {
                            $text = trim($matches[1]);
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

            // 宣言構文に対するコメントの場合、コメント形式をPHPDoc形式に変換する
            if ($this->isCommentForStructure($phpcsFile, $commentEndPtr) === true) {
                $fix = $phpcsFile->addFixableError(
                    self::ERROR_MESSAGE,
                    $stackPtr,
                    "InvalidCommentFormat"
                );
                if ($fix === true) {
                    $phpcsFile->fixer->replaceToken($stackPtr, "/**\n");
                }

                return;
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
                    $phpcsFile->fixer->replaceToken($commentEndPtr + 1, "");
                    $phpcsFile->fixer->endChangeset();
                }
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

        // 1行コメント形式の場合
        if ($openTagLine === $closeTagLine) {
            $content = join(
                array_map(
                    fn(array $token): string => $token["content"],
                    $docCommentTokens
                )
            );
            preg_match("/^\/\*\*(.+)\*\/$/", $content, $matches);
            $commentBody = $matches[1];

            // @の存在しないインラインPHPDoc形式はふさわしくないので、インラインコメントへの自動修正の対象とする
            if (str_contains($commentBody, "@") === false) {
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

        /*
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

        $hasAnnotations = count(
                array_filter($docCommentTokens, fn(array $token): bool => str_contains($token["content"], "@") === true)
            ) > 0;
        foreach ($formattedDocCommentTokens as $index => $token) {

            $currentContent = $token["lineContent"];
            $beginPtr = $token["originalTokens"][0]["pointer"];
            $endPtr = $token["originalTokens"][array_key_last($token["originalTokens"])]["pointer"];

            // コメント開始行
            if ($index === 0) {

                if ((bool)preg_match("/^\/\*\*\n/", $currentContent) === false) {
                    preg_match("/^\/\*\*(.*)$/", $currentContent, $matches);
                    assert(is_string($matches[1]) === true);
                    $fix = $phpcsFile->addFixableError(
                        self::ERROR_MESSAGE,
                        $beginPtr,
                        "InvalidCommentFormat"
                    );
                    if ($fix === true) {
                        $text = trim($matches[1]);
                        // 開始タグのみ、空白が含まれないので基準インデントは次の行から適用している。
                        $fixedContent = (function () use ($text, $baseIndent, $hasAnnotations): string {
                            if ($text === "") {
                                if ($hasAnnotations === true) {
                                    return "/**\n";
                                }

                                return "/*\n";
                            }

                            if ($hasAnnotations === true) {
                                return "/**\n{$baseIndent} * {$text}\n";
                            }

                            return "/*\n{$baseIndent} * {$text}\n";
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

                /*
                 * PHPDocコメントにアノテーションが含まれていない場合は
                 * PHPDoc形式である必要が無いので通常のコメント形式に変換する。
                 * 宣言構文に対するコメントの場合は、変換しない。
                 */
                if ($hasAnnotations === false && $this->isCommentForStructure($phpcsFile, $closeTagPtr) === false) {
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
                            replacement: "/*\n",
                        );
                    }
                }

                continue;
            }

            // コメント終了行
            if ($index === array_key_last($formattedDocCommentTokens)) {
                if ($currentContent !== "{$baseIndent} */") {
                    preg_match("/^(.*)\*\//", $currentContent, $matches);
                    $fix = $phpcsFile->addFixableError(
                        self::ERROR_MESSAGE,
                        $beginPtr,
                        "InvalidCommentFormat"
                    );
                    if ($fix === true) {
                        $text = trim($matches[1]);
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
            preg_match("/^ * \* (.*)\n$/", $centerBodyToken["lineContent"], $matches);
            $body = trim($matches[1]);

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
        $foundTokenPtr = TokenHelper::findNextExcluding(
            $phpcsFile,
            [
                T_WHITESPACE,
                T_PUBLIC,
                T_PROTECTED,
                T_PRIVATE,
                T_STATIC,
                T_FINAL,
            ],
            $commentCloseTagPtr + 1
        );
        if (is_int($foundTokenPtr) === true
            && in_array(
                $tokens[$foundTokenPtr]["code"] ?? [],
                [
                    T_CLASS,
                    T_INTERFACE,
                    T_TRAIT,
                    T_ENUM,
                    T_FUNCTION,
                    T_ABSTRACT,
                ],
                true
            ) === true) {
            return true;
        }

        return false;
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
            $commentEndPtr = $phpcsFile->findNext(types: T_COMMENT, start: $i);
            if (is_int($commentEndPtr) === false) {
                return null;
            }

            if (str_ends_with($tokens[$commentEndPtr]["content"], "*/") === true) {
                return $commentEndPtr;
            }
        }
    }
}
