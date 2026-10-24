<picture>
    <source srcset="https://github.com/PrinsFrank/pdfparser/raw/main/docs/images/banner_dark.png" media="(prefers-color-scheme: dark)">
    <img src="https://github.com/PrinsFrank/pdfparser/raw/main/docs/images/banner_light.png" alt="Banner">
</picture>

# PDF Parser

> **This is a fork** of [prinsfrank/pdfparser](https://github.com/PrinsFrank/pdfparser) used by OneLib. It adds an
> experimental page renderer, reading PDFs over HTTP Range requests and a set of parser fixes. The namespace is
> unchanged (`PrinsFrank\PdfParser`) so upstream changes can still be merged; the upstream code is on the
> [`upstream`](https://github.com/vuthaihoc/pdfparser/tree/upstream) branch. The rest of this README is upstream's,
> from "Why this library?" on.

## What this fork adds

### Installation

```bash
composer require vuthaihoc/pdfparser
```

The package is on [Packagist](https://packagist.org/packages/vuthaihoc/pdfparser). Releases of this fork continue
upstream's numbering from v3.9.0 (upstream v3.8.0 + this fork's changes + upstream main up to the release);
`dev-main` (alias `3.x-dev`) follows the main branch.

The renderer needs `ext-imagick` (ImageMagick with FreeType; Liberation fonts and `fc-match` for fonts that are not
embedded), `HttpRangeStream` needs `ext-curl`.

### Rendering pages (`PrinsFrank\PdfParser\Render`, experimental)

```php
use PrinsFrank\PdfParser\PdfParser;
use PrinsFrank\PdfParser\Render\PageRenderer;
use PrinsFrank\PdfParser\Render\RenderOptions;

$document = (new PdfParser())->parseFile('document.pdf');
$renderer = new PageRenderer($document, RenderOptions::preset('balanced'));
$result = $renderer->render($document->getPage(1), 300); // 300 px wide

$result->image->writeImage('page-1.png'); // Imagick
$result->score;   // 0..1: how close the image probably is to a real renderer, from what was skipped or approximated
$result->issues;  // what was skipped or approximated, with the share of the page it covers
$result->timings; // seconds per stage: parse, interpret, text, fonts, image_decode, image_place, shading, rasterize, ...
$result->memory;  // PHP and ImageMagick peaks
```

It draws paths, clipping, images (all filters and colour spaces, soft masks), forms, axial and radial shadings,
annotation appearances and text. Text is drawn with the fonts embedded in the PDF: TrueType, CFF and Type 1 programs
are rebuilt to be drawn by glyph id with the PDF widths, so text lands where the PDF puts it; fonts that are not
embedded get a substitute system font stretched to the PDF width. Type 3 fonts, patterns other than shadings, blend
modes and soft masks of groups are approximated, and count in the score.

Presets (`RenderOptions::preset($name)` or the static constructors), measured on 336 pages of mixed documents at
300 px against `pdftoppm`:

| Preset | Time | SSIM | For |
|---|---|---|---|
| `balanced` (default) | 100 % (0.35 s/page) | 0.881 | general use |
| `quality` | 122 % | 0.892 | closest result: 3× supersampling, Lanczos |
| `fast` | 67 % | 0.855 | no supersampling, one run per string |
| `thumbnail` | 68 % | 0.864 | previews up to ~200 px: substitute fonts |
| `low_memory` | 85 % | 0.862 | small workers: ImageMagick pixel cache capped at 64 MB |
| `draft` | 48 % | 0.793 | layout only: grey boxes for images |

Each option can also be set on its own (`new RenderOptions(supersample: 1, images: RenderOptions::IMAGES_BOX, ...)`):
supersampling and downscale filter, embedded/substitute/no text, TJ split threshold, full/box/no images, image
resolution and masks, paths, clipping, shading bands, annotations, ImageMagick memory and thread limits.

ImageMagick 6 leaks about 20 KB every time the font of a text changes; the renderer groups text by font to keep this
to well under 1 MB per page, but long-running workers should still be restarted now and then.

### Reading over HTTP Range requests

```php
use PrinsFrank\PdfParser\Stream\HttpRangeStream;

$document = (new PdfParser())->parse(HttpRangeStream::open('https://example.com/document.pdf'));
$document->getPage(1)?->getText(); // reads the trailer, cross reference and objects of that page only
```

`RangeStream` fetches and caches blocks (64 KiB by default) through any callable, so other transports (S3, ...) can be
plugged in. Without Range support on the server the whole file is read once.

### Parser fixes

- Page `/Contents` and Type0 `/DescendantFonts` that refer to an array of references.
- `Tj`/`TJ`/`"` operands tokenized: adjustments after whitespace or written as `.5`, escaped and nested parentheses,
  `"` with spaces in its string.
- Character codes from the bytes of a string (hexadecimal strings of Type0 fonts were read as 16-bit pairs).
- FlateDecode PNG predictors per pixel and per row length (multi-component images came out as garbage) and much
  faster, TIFF predictor 2.
- Content strings ending in an escaped backslash; widths of composite fonts by 2-byte codes.
- A dictionary entry with a value of an unexpected type is skipped instead of failing the whole dictionary.
- `sh` and `b` operators; inline images as one `EI` command.
- An empty stream is an empty string.


[![GitHub](https://img.shields.io/badge/license-MIT-green)](https://github.com/PrinsFrank/pdfparser/blob/main/LICENSE)
[![PHP Version Support](https://img.shields.io/packagist/php-v/prinsfrank/pdfparser)](https://github.com/PrinsFrank/pdfparser/blob/main/composer.json)
[![codecov](https://codecov.io/gh/PrinsFrank/pdfparser/branch/main/graph/badge.svg?token=2KXO43MCIC)](https://codecov.io/gh/PrinsFrank/pdfparser)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%2010-brightgreen.svg?style=flat)](https://github.com/PrinsFrank/pdfparser/blob/main/phpstan.neon)
[![](https://img.shields.io/static/v1?label=Sponsor&message=%E2%9D%A4&logo=GitHub&color=%23fe8e86)](https://github.com/sponsors/PrinsFrank)

> **1.5x faster** and **4.4x less memory** than smalot/pdfparser. Actively maintained, MIT licensed, and built from scratch for PHP8.1+

> :owl: This library is written by a human who understands all of its code. As such, it is small enough for a person to have a mental image of it, and no cognitive offloading is needed. AI-generated PRs are not rejected outright, but they should be [small and focused](CONTRIBUTING.md).

## Why this library?

Previously, there wasn't a PDF library that allows parsing of PDFs that was open source, MIT licensed and under active development. The PDFParser by smalot, while having been very useful over the years isn't under active development anymore. The parser of Setasign is not MIT licensed and not open source. And several other packages rely on java/js/python dependencies being installed that are called by PHP behind the scenes, losing any type information and underlying structure.

Instead, this package allows for parsing of a wide variety of PDF files while not relying on external dependencies, all while being MIT licensed!

## Comparison with `smalot/pdfparser`

| Metric               | prinsfrank/pdfparser                                              | smalot/pdfparser    |
|----------------------|-------------------------------------------------------------------|---------------------|
| Speed                | On average **1.5x faster**, up to **110x faster on complex PDFs** | baseline            |
| Memory               | On average **4.4x less memory**                                   | baseline            |
| Success rate         | **100%** of test files                                            | 95.3%               |
| Encrypted PDFs       | Supported                                                         | Not supported       |
| Extract as markdown  | Yes, including: Headings, Bold, Italic, Links!                    | No                  |
| Multi-column support | Yes                                                               | No                  |
| PHP version          | PHP 8.1+                                                          | PHP 7.1+            |
| Active development   | Yes                                                               | Limited maintenance |
| PHPStan Level        | 10 (max)                                                          | 3                   |

<details>
    <summary>View detailed benchmark results</summary>
    <picture>
        <source srcset="https://prinsfrank.github.io/pdfparser-benchmarks/comparison_dark.png" media="(prefers-color-scheme: dark)">
        <img src="https://prinsfrank.github.io/pdfparser-benchmarks/comparison_light.png" alt="Banner">
    </picture>
</details>

Or see the [benchmark repository](https://github.com/PrinsFrank/pdfparser-benchmarks) for more details.

## Setup

To start right away, run the following command in your composer project;

```bash
composer require prinsfrank/pdfparser
```

(For this fork, see [Installation](#installation) above.)

<details>
    <summary>Installation without Composer</summary>
    <p>If you don't want to install this package using Composer, or cannot due to some constraints, you can still download a phar file of the latest release, which bundles all the required dependencies. To do so, head to the "releases" section on the github repository, and click on "pdfparser.phar" under "Assets". Place the phar file somewhere you can access it from your code</p>
    <p>As you don't have Composer to handle autoloading for you, you'll need to register the autoloader from the phar file. To do so, simply add the following line at the top of your custom bootstrap script or the file you want to parse PDFs in:</p>
    <code>require 'phar://pdfparser.phar/vendor/autoload.php';</code>
</details>

The most common use case - extracting text from a document - is then just as simple as this;

```php
use PrinsFrank\PdfParser\PdfParser;

$document = (new PdfParser())
    ->parseFile($path);

$document->getText();
```

Or, if you prefer markdown:

```php
use PrinsFrank\PdfParser\PdfParser;

$document = (new PdfParser())
    ->parseFile($path);

$document->getMarkdown();
```

## 💖 Sponsorship

If you depend on this package and want to support its maintenance, please consider sponsoring me. I'll continue maintaining and releasing updates regardless, but [sponsorships](https://github.com/sponsors/PrinsFrank) help cover the time it takes to review changes and keep everything accurate.

## Opening a PDF

To open a PDF file, you'll first need to load it and retrieve a `Document` object. That can be done by either parsing a file directly, or parsing a PDF from a string variable.

### Parsing a PDF file

Parsing a PDF from a file directly is the easiest option. To do so, simply call the `parseFile` method on a `PdfParser` instance:

```php
use PrinsFrank\PdfParser;

$document = (new PdfParser())
    ->parseFile(dirname(__DIR__, 3) . '/path/to/file.pdf');
```

By default, this loads the file into memory while parsing. This greatly improves parsing speed, at the cost of a bigger memory footprint. If you want to reduce the base memory footprint and use a file handle instead, you can set the second parameter `useInMemoryStream` to `false`.

### Parsing PDF from string

It is also possible to parse a PDF from a string in a variable. To do so, pass the string as an argument for the `parseFile` method on a `PdfParser` instance.

```php
use PrinsFrank\PdfParser;

$pdfAsString = file_get_contents(dirname(__DIR__, 3) . '/path/to/file.pdf');

$document = (new PdfParser())
    ->parseString($pdfAsString);
```

If you want to decrease the average memory footprint, you can also do so here, by setting the `useFileCache` parameter to `true`. This will result in the file content being written to a temporary file and the parser using the handle to that file from then on. This will be at the cost of speed.

## The `Document`

Once you have opened a file from the filesystem with `parseFile` or from a string variable using `parseString`, you'll get back an instance of a `Document`.

While initially parsing the document, a small number of variables are populated in the `Document` instance that allow for further accessing of that document. This includes:
- The public `$stream` property: a PHP stream handle to the file in memory or on the filesystem.
- The public `$version` property: Information about the PDF version of the file.
- the public `$crossReferenceSource` property: A parsed crossReference table or stream, containing several crossReference(Sub)Sections that contain information about objects stored in the document and where to find them.
- The private `$pages` property to cache any pages that have already been retrieved. This property is only set when the pages are actually retrieved using the `getPages` method. (See below)

The document also contains several methods to retrieve specific objects from it. Those are discussed below.

If you want to quickly retrieve all text from a document, you can use the `getText` method.

## Objects in a `Document` and their decorators

A PDF is organized in objects. Not all objects are created equally. Some objects might be a Page, while others a Font. Some objects might be Generic and without a specific type. There are currently 18 specific types, and a generic object type. Some of those will be specified below.

Code specific for certain object types lives in that object types' decorator. Retrieving text for a Page makes sense, retrieving the text from a Font not so much, so the Page decorator contains the `getText` method. Below you'll find some documentation for specific object decorators.

If you want to retrieve an object by its number, you can call the `$document->getObject($objectNumber)` method. If you know that the object with that number is supposed to be of a specific type, you can supply the second argument. For example, if you want to get object 42 which you know is of type Page, you can call the method like this:

```php
$page = $document->getObject(42, Page::class);
```

If the object is not of the correct type, this will result in an exception. If you don't care about the object type, pass null as the second argument or don't supply the second argument at all.

### Decorated `InformationDictionary` objects

If a PDF has a title, producer, author, creator, creationDate or modificationDate, it is stored in an InformationDictionary.

If a PDF has an InformationDictionary, it can be retrieved using the `$document->getInformationDictionary()` method. Not All PDFs have this available, so this method might return null.

To access information from the InformationDictionary, there are several methods available:

```php
/** @var \PrinsFrank\PdfParser\Document\Document $document */
$title = $document->getInformationDictionary()?->getTitle(); 
$producer = $document->getInformationDictionary()?->getProducer(); 
$author = $document->getInformationDictionary()?->getAuthor(); 
$creator = $document->getInformationDictionary()?->getCreator(); 
$creationDate = $document->getInformationDictionary()?->getCreationDate(); 
$modificationDate = $document->getInformationDictionary()?->getModificationDate(); 
```

If you want to access non-standard data from the information dictionary, you can also retrieve the entire dictionary from the object:

```php
/** @var \PrinsFrank\PdfParser\Document\Document $document */
$dictionary = $document->getInformationDictionary()?->getDictionary();
```

### Decorated `Page` objects

Page objects can be retrieved from a document by calling the `$document->getPage($pageNumber)` method for a single page, or `$document->getPages()` for all pages. Note that `$pageNumber` is zero-indexed, so even if different format page numbers are displayed at the bottom of a page, the first page in a document is still page 0, etc.

Once you have a `Page` object, there are several methods available to retrieve information from that page. The main method of interest here is the `$page->getText()` method. To retrieve all text from all pages, you could do something like this:

```php
use PrinsFrank\PdfParser\PdfParser;

$document = (new PdfParser())->parseFile('/path/to/file.pdf');

foreach ($document->getPages() as $index => $page) {
    echo 'Text on page ' . $index . ' : ' . $page->getText();
}
```

There is also a `getText` method available on the Document to retrieve all text at once without even having to retrieve pages.

There are also methods available to get the underlying positioned Text Elements using `$page->getPositionedTextElements()`, the resource dictionary for a page using `$page->getResourceDictionary()` and the font dictionary using `$page->getFontDictionary()`.

### Decorated `XObject` objects

Images and forms are stored in XObjects. These can be retrieved on a page-by-page basis using `$page->getXObjects()`. If you are only interested in images, you can retrieve all image XObjects by calling `$page->getImages()`.

For XObjects, there are some additional methods: `getWidth` returns the width of the object in pixels if available, `getHeight()` the height in pixels, and `getLength()` the length in bytes. To determine the subtype, there are two methods available: `isImage()` and `isForm`. If the XObject is an image, `getImageType()` will return the image type.

To extract all images on a page and store them on your machine, you could do something like this:

```php
/** @var \PrinsFrank\PdfParser\Document\Document $document */
foreach ($document->getImages() as $index => $image) {
    if (($imageFileExtension = $image->getImageType()?->getFileExtension()) === null) {
        continue; // You could still save the file with a default file extension like 'jpg', but it is not clear what kind of image this is.
    }

    file_put_contents(sprintf('%s/image_%d.%s', __DIR__, $index, $imageFileExtension), $image->getContent());
}
```
