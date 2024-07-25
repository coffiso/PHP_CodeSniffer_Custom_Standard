# PHP_CodeSniffer_Custom_Standard
php_codesnifferの拡張ルール

## ルール一覧
🔧 = エラーの自動修正に対応

## Functions
### CustomStandard.Functions.RequireClosureArgumentTypeHint
無名関数の引数に型ヒントがない場合に指摘します。
### CustomStandard.Functions.RequireClosureReturnTypeHint
無名関数の戻り値に型ヒントがない場合に指摘します。
### CustomStandard.Functions.ForbiddenVariadicArguments
可変長引数を禁止します。
### CustomStandard.Functions.ForbiddenDefaultArgumentValues
デフォルト引数を禁止します。

## Strings
### CustomStandard.Strings.RequireDoubleQuotes 🔧
文字列リテラルの引用符をダブルクォート「"」に強制します。

### CustomStandard.Strings.HeredocQuotes
ヒアドキュメントの引用符を`EOL`に制限します。

## Classes
### CustomStandard.Classes.RequireReadOnlyProperties
読み取り専用インスタンスプロパティを必須にします。
