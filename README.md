# PHP_CodeSniffer Custom Standard
php_codesniffer 用のカスタムルール集です。リポジトリ内の Sniff 実装に合わせて README を整備しています。

## 概要
- このパッケージは PHP_CodeSniffer (PHPCS) 向けのカスタムルール（Sniff）群を提供します。
- ruleset は `CustomStandard/ruleset.xml` を参照してください。

## 使い方（簡易）
- Composer 経由でプロジェクトに追加し、`phpcs --standard=CustomStandard` で実行します。

## ルール一覧
🔧 = 自動修正（fixer）に対応しているルール

### Functions
- **CustomStandard.Functions.StandaloneFunctionFileName**: スタンドアロン関数を定義する単一の PHP ファイルに対して、ファイル名とトップレベル関数名の完全一致（ケース・センシティブ）を強制します。また、ファイル名・関数名はロワーキャメルケースのみ許可し、1ファイルにトップレベル関数が2つ以上ある定義を禁止します。

  - 要件:

    - ファイル名（拡張子 .php を除く）とトップレベルで最初に出現する関数名が完全に一致すること（大文字小文字を区別）。
    - ファイル名・関数名はロワーキャメルケースのみ許可（先頭小文字、アンダースコア禁止、英数字）。
    - 1ファイルにトップレベル関数は 1 つのみ許可。クロージャやクラス・トレイト・インターフェイス内部のメソッドは対象外。

  - OK:

  ```php
  <?php
  // ファイル名: utilFunction.php
  function utilFunction()
  {
      return true;
  }
  ```

  - NG (例):

  ```php
  <?php
  // ファイル名: utilfunction.php (小文字不一致)
  function utilFunction()
  {
  }

  // または
  // ファイルに複数のトップレベル関数がある場合
  function one() {}
  function two() {}
  ```

  - 実行例:

  ```bash
  vendor/bin/phpcs --standard=CustomStandard path/to/utilFunction.php
  ```

  - 備考: このリポジトリには別途「名前空間のないグローバル関数を禁止する」Sniff が有効な場合があり、その場合はグローバル関数について別エラーが出ることがあります。不要であれば `ruleset.xml` で当該 Sniff を無効化できます（無効化を希望すれば私が設定を追加します）。

- **CustomStandard.Functions.RequireClosureArgumentTypeHint**: 無名関数・アロー関数の引数に型ヒントを要求します。

  - OK:

  ```php
  <?php
  $f = function (int $a, string $b): void {
      // OK: 引数に型がある
  };

  $g = fn(int $x): int => $x * 2;
  ```

  - NG:

  ```php
  <?php
  $f = function ($a, $b) {
      // NG: 引数に型ヒントがない
  };

  $g = fn($x) => $x * 2;
  ```

- **CustomStandard.Functions.RequireClosureReturnTypeHint**: 無名関数・アロー関数の戻り値に型ヒントを要求します。

  - OK:

  ```php
  <?php
  $f = function (int $a): int {
      return $a;
  };

  $g = fn(int $x): int => $x * 2;
  ```

  - NG:

  ```php
  <?php
  $f = function (int $a) {
      return $a;
  };

  $g = fn(int $x) => $x * 2; // NG: 戻り値型がない
  ```

- **CustomStandard.Functions.ForbiddenVariadicArguments**: 可変長引数（`...`）を禁止し、配列での受け取りを要求します。`@inheritdoc` の付いたメソッドは除外されます。

  - OK:

  ```php
  <?php
  function foo(array $items): void
  {
      // 可変長引数の代わりに配列を受け取る
  }
  ```

  - NG:

  ```php
  <?php
  function foo(...$items): void
  {
      // NG: `...` の使用は禁じられている
  }
  ```

- **CustomStandard.Functions.ForbiddenDefaultArgumentValues**: デフォルト引数の使用を禁止します。`@inheritdoc` の付いたメソッドは除外されます。

  - OK:

  ```php
  <?php
  function bar(int $a, string $b): void
  {
      // OK: デフォルト値を持たない
  }
  ```

  - NG:

  ```php
  <?php
  function bar(int $a = 1, string $b = 'x'): void
  {
      // NG: デフォルト引数は許可されない
  }
  ```

- **CustomStandard.Functions.ForbidGlobalFunction**: 名前空間の無いグローバル関数の定義を禁止します（トップレベルの命名された関数を検出します）。

  - OK:

  ```php
  <?php
  namespace App\Functions;

  function foo(): void {}
  ```

  - NG:

  ```php
  <?php
  function foo(): void {}
  // NG: 名前空間のないグローバル関数は禁止
  ```

### Strings

 - **CustomStandard.Functions.PreferFunctionOverClass**: 単純なユーティリティクラスや単一メソッドクラスを関数定義に置き換えることを推奨します。検出対象の例:
   - 静的メソッドのみを持ちプロパティやコンストラクタを持たないユーティリティクラス
   - プロパティや識別子がなくメソッドを1つだけ持つクラス

   - 注意: 継承や implements を使用しているクラス、コンストラクタで状態を初期化しているクラス、プロパティを持つクラスは検出対象外です。

 - **CustomStandard.Strings.RequireDoubleQuotes** 🔧: 文字列リテラルをダブルクォートで囲むことを推奨します。

   - OK:

     ```php
     <?php
     $s = "hello world";
     ```

   - NG:

     ```php
     <?php
     $s = 'hello world';
     ```

 - **CustomStandard.Strings.HeredocQuotes**: ヒアドキュメントの識別子を "EOL" に限定します。

   - OK:

     ```php
     <?php
     $heredoc = <<<EOL
     line1
     line2
     EOL;
     ```

   - NG:

     ```php
     <?php
     $heredoc = <<<FOO
     line1
     FOO;
     // NG: ヒアドキュメントの識別子は EOL を期待
     ```

### Classes
- **CustomStandard.Classes.RequireReadOnlyClass** 🔧: プロパティの状況に応じてクラスを `readonly` にすることを推奨・自動修正します。クラスが `readonly` であるべきか検出し、必要に応じて `readonly` を付与したり、`readonly` 修飾子をプロパティから削除する自動修正を行います（staticプロパティや継承可能なクラス、型宣言の無いプロパティ等は自動修正対象外となる場合があります）。

  - OK (class を readonly として宣言し、プロパティは通常の型宣言にする例):

    ```php
    <?php
    readonly class User
    {
        public int $id;
        public string $name;
    }
    ```

  - NG (すべてのプロパティが `readonly` 指定されているがクラス自体が `readonly` ではない例 — クラスを readonly に昇格させるべき):

    ```php
    <?php
    class User
    {
        public readonly int $id;
        public readonly string $name;
    }
    // NG: 全プロパティが readonly なのでクラスを readonly にすることが推奨される
    ```

### TypeHints
- **CustomStandard.TypeHints.ForbiddenTypeUsage**: 設定された禁止型の使用を検出します。検出対象は以下を含みます:
  - 関数・メソッドの引数型宣言
  - 関数・メソッドの戻り値型宣言
  - プロパティの型宣言
  - Union/Intersection 型内での使用
  - クロージャ・Arrow関数での型使用

設定は `ruleset.xml` から `forbiddenTypes` プロパティで行います。例:
```xml
<rule ref="CustomStandard.TypeHints.ForbiddenTypeUsage">
    <properties>
        <property name="forbiddenTypes" type="array">
            <element key="DateTime" value="DateTime の代わりに DateTimeInterface を使用してください。"/>
            <element key="stdClass" value="stdClass の使用は禁止されています。"/>
        </property>
    </properties>
</rule>
```

  - 例（`forbiddenTypes` に `DateTime` や `stdClass` を設定した場合）

    - OK:

      ```php
      <?php
      function ok(DateTimeInterface $dt): DateTimeInterface
      {
          return $dt;
      }

      class C
      {
          public MyDto $dto; // OK: 禁止対象でない型
      }
      ```

    - NG:

      ```php
      <?php
      function ng(DateTime $dt): DateTime
      {
          return $dt; // NG: DateTime の使用が禁止されている想定
      }

      class C
      {
          public stdClass $data; // NG: stdClass の使用が禁止される設定例
      }
      ```

### Commenting
- **CustomStandard.Commenting.CommentingFormat** 🔧: コメント書式の統一を行うルールです。以下のような整形・変換を行います:
  - `#` コメントを `//` に変換
  - 単一行 `/* */` コメントを `//` に変換
  - 連続する `//` コメントを PHPDoc 形式に変換
  - PHPDoc の整形（インデントや余分な空行の削除など）

※ 詳細な例はソースの実装を参照してください。

  - OK:

    ```php
    <?php
    // Proper single line comment

    /**
     * Proper PHPDoc with annotations
     * @var string $name
     */
    ```

  - NG:

    ```php
    <?php
    # hash style comment
    //comment_without_space
    /* inline comment */
    ```

### Namespaces
- **CustomStandard.Namespaces.ForbidAliasedUse**: `use Foo\Bar as Baz;` のようなエイリアス付きインポートを禁止します。部分インポート（`use Foo\Bar;`）を利用してください。

  - OK:

    ```php
    <?php
    use Foo\Bar;
    ```

  - NG:

    ```php
    <?php
    use Foo\Bar as Baz; // NG: エイリアス付きインポートは禁止
    ```

