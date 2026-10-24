<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Render;

/**
 * Predefined data of the Compact Font Format (Adobe Technical Note #5176, appendices A to C): the standard strings
 * (SIDs 0..390; SIDs 0..228 are also the ISOAdobe charset), the Expert and ExpertSubset charsets as names, and
 * StandardEncoding (also the encoding of Type 1 fonts that declare StandardEncoding).
 *
 * @experimental
 */
final class CffStandard {
    /** @var list<string> */
    public const STRINGS = [
        '.notdef', 'space', 'exclam', 'quotedbl', 'numbersign', 'dollar', 'percent', 'ampersand',
        'quoteright', 'parenleft', 'parenright', 'asterisk', 'plus', 'comma', 'hyphen', 'period',
        'slash', 'zero', 'one', 'two', 'three', 'four', 'five', 'six',
        'seven', 'eight', 'nine', 'colon', 'semicolon', 'less', 'equal', 'greater',
        'question', 'at', 'A', 'B', 'C', 'D', 'E', 'F',
        'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N',
        'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V',
        'W', 'X', 'Y', 'Z', 'bracketleft', 'backslash', 'bracketright', 'asciicircum',
        'underscore', 'quoteleft', 'a', 'b', 'c', 'd', 'e', 'f',
        'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n',
        'o', 'p', 'q', 'r', 's', 't', 'u', 'v',
        'w', 'x', 'y', 'z', 'braceleft', 'bar', 'braceright', 'asciitilde',
        'exclamdown', 'cent', 'sterling', 'fraction', 'yen', 'florin', 'section', 'currency',
        'quotesingle', 'quotedblleft', 'guillemotleft', 'guilsinglleft', 'guilsinglright', 'fi', 'fl', 'endash',
        'dagger', 'daggerdbl', 'periodcentered', 'paragraph', 'bullet', 'quotesinglbase', 'quotedblbase', 'quotedblright',
        'guillemotright', 'ellipsis', 'perthousand', 'questiondown', 'grave', 'acute', 'circumflex', 'tilde',
        'macron', 'breve', 'dotaccent', 'dieresis', 'ring', 'cedilla', 'hungarumlaut', 'ogonek',
        'caron', 'emdash', 'AE', 'ordfeminine', 'Lslash', 'Oslash', 'OE', 'ordmasculine',
        'ae', 'dotlessi', 'lslash', 'oslash', 'oe', 'germandbls', 'onesuperior', 'logicalnot',
        'mu', 'trademark', 'Eth', 'onehalf', 'plusminus', 'Thorn', 'onequarter', 'divide',
        'brokenbar', 'degree', 'thorn', 'threequarters', 'twosuperior', 'registered', 'minus', 'eth',
        'multiply', 'threesuperior', 'copyright', 'Aacute', 'Acircumflex', 'Adieresis', 'Agrave', 'Aring',
        'Atilde', 'Ccedilla', 'Eacute', 'Ecircumflex', 'Edieresis', 'Egrave', 'Iacute', 'Icircumflex',
        'Idieresis', 'Igrave', 'Ntilde', 'Oacute', 'Ocircumflex', 'Odieresis', 'Ograve', 'Otilde',
        'Scaron', 'Uacute', 'Ucircumflex', 'Udieresis', 'Ugrave', 'Yacute', 'Ydieresis', 'Zcaron',
        'aacute', 'acircumflex', 'adieresis', 'agrave', 'aring', 'atilde', 'ccedilla', 'eacute',
        'ecircumflex', 'edieresis', 'egrave', 'iacute', 'icircumflex', 'idieresis', 'igrave', 'ntilde',
        'oacute', 'ocircumflex', 'odieresis', 'ograve', 'otilde', 'scaron', 'uacute', 'ucircumflex',
        'udieresis', 'ugrave', 'yacute', 'ydieresis', 'zcaron', 'exclamsmall', 'Hungarumlautsmall', 'dollaroldstyle',
        'dollarsuperior', 'ampersandsmall', 'Acutesmall', 'parenleftsuperior', 'parenrightsuperior', 'twodotenleader', 'onedotenleader', 'zerooldstyle',
        'oneoldstyle', 'twooldstyle', 'threeoldstyle', 'fouroldstyle', 'fiveoldstyle', 'sixoldstyle', 'sevenoldstyle', 'eightoldstyle',
        'nineoldstyle', 'commasuperior', 'threequartersemdash', 'periodsuperior', 'questionsmall', 'asuperior', 'bsuperior', 'centsuperior',
        'dsuperior', 'esuperior', 'isuperior', 'lsuperior', 'msuperior', 'nsuperior', 'osuperior', 'rsuperior',
        'ssuperior', 'tsuperior', 'ff', 'ffi', 'ffl', 'parenleftinferior', 'parenrightinferior', 'Circumflexsmall',
        'hyphensuperior', 'Gravesmall', 'Asmall', 'Bsmall', 'Csmall', 'Dsmall', 'Esmall', 'Fsmall',
        'Gsmall', 'Hsmall', 'Ismall', 'Jsmall', 'Ksmall', 'Lsmall', 'Msmall', 'Nsmall',
        'Osmall', 'Psmall', 'Qsmall', 'Rsmall', 'Ssmall', 'Tsmall', 'Usmall', 'Vsmall',
        'Wsmall', 'Xsmall', 'Ysmall', 'Zsmall', 'colonmonetary', 'onefitted', 'rupiah', 'Tildesmall',
        'exclamdownsmall', 'centoldstyle', 'Lslashsmall', 'Scaronsmall', 'Zcaronsmall', 'Dieresissmall', 'Brevesmall', 'Caronsmall',
        'Dotaccentsmall', 'Macronsmall', 'figuredash', 'hypheninferior', 'Ogoneksmall', 'Ringsmall', 'Cedillasmall', 'questiondownsmall',
        'oneeighth', 'threeeighths', 'fiveeighths', 'seveneighths', 'onethird', 'twothirds', 'zerosuperior', 'foursuperior',
        'fivesuperior', 'sixsuperior', 'sevensuperior', 'eightsuperior', 'ninesuperior', 'zeroinferior', 'oneinferior', 'twoinferior',
        'threeinferior', 'fourinferior', 'fiveinferior', 'sixinferior', 'seveninferior', 'eightinferior', 'nineinferior', 'centinferior',
        'dollarinferior', 'periodinferior', 'commainferior', 'Agravesmall', 'Aacutesmall', 'Acircumflexsmall', 'Atildesmall', 'Adieresissmall',
        'Aringsmall', 'AEsmall', 'Ccedillasmall', 'Egravesmall', 'Eacutesmall', 'Ecircumflexsmall', 'Edieresissmall', 'Igravesmall',
        'Iacutesmall', 'Icircumflexsmall', 'Idieresissmall', 'Ethsmall', 'Ntildesmall', 'Ogravesmall', 'Oacutesmall', 'Ocircumflexsmall',
        'Otildesmall', 'Odieresissmall', 'OEsmall', 'Oslashsmall', 'Ugravesmall', 'Uacutesmall', 'Ucircumflexsmall', 'Udieresissmall',
        'Yacutesmall', 'Thornsmall', 'Ydieresissmall', '001.000', '001.001', '001.002', '001.003', 'Black',
        'Bold', 'Book', 'Light', 'Medium', 'Regular', 'Roman', 'Semibold',
    ];

    /** @var list<string> */
    public const EXPERT_CHARSET = [
        '.notdef', 'space', 'exclamsmall', 'Hungarumlautsmall', 'dollaroldstyle', 'dollarsuperior', 'ampersandsmall', 'Acutesmall',
        'parenleftsuperior', 'parenrightsuperior', 'twodotenleader', 'onedotenleader', 'comma', 'hyphen', 'period', 'fraction',
        'zerooldstyle', 'oneoldstyle', 'twooldstyle', 'threeoldstyle', 'fouroldstyle', 'fiveoldstyle', 'sixoldstyle', 'sevenoldstyle',
        'eightoldstyle', 'nineoldstyle', 'colon', 'semicolon', 'commasuperior', 'threequartersemdash', 'periodsuperior', 'questionsmall',
        'asuperior', 'bsuperior', 'centsuperior', 'dsuperior', 'esuperior', 'isuperior', 'lsuperior', 'msuperior',
        'nsuperior', 'osuperior', 'rsuperior', 'ssuperior', 'tsuperior', 'ff', 'fi', 'fl',
        'ffi', 'ffl', 'parenleftinferior', 'parenrightinferior', 'Circumflexsmall', 'hyphensuperior', 'Gravesmall', 'Asmall',
        'Bsmall', 'Csmall', 'Dsmall', 'Esmall', 'Fsmall', 'Gsmall', 'Hsmall', 'Ismall',
        'Jsmall', 'Ksmall', 'Lsmall', 'Msmall', 'Nsmall', 'Osmall', 'Psmall', 'Qsmall',
        'Rsmall', 'Ssmall', 'Tsmall', 'Usmall', 'Vsmall', 'Wsmall', 'Xsmall', 'Ysmall',
        'Zsmall', 'colonmonetary', 'onefitted', 'rupiah', 'Tildesmall', 'exclamdownsmall', 'centoldstyle', 'Lslashsmall',
        'Scaronsmall', 'Zcaronsmall', 'Dieresissmall', 'Brevesmall', 'Caronsmall', 'Dotaccentsmall', 'Macronsmall', 'figuredash',
        'hypheninferior', 'Ogoneksmall', 'Ringsmall', 'Cedillasmall', 'onequarter', 'onehalf', 'threequarters', 'questiondownsmall',
        'oneeighth', 'threeeighths', 'fiveeighths', 'seveneighths', 'onethird', 'twothirds', 'zerosuperior', 'onesuperior',
        'twosuperior', 'threesuperior', 'foursuperior', 'fivesuperior', 'sixsuperior', 'sevensuperior', 'eightsuperior', 'ninesuperior',
        'zeroinferior', 'oneinferior', 'twoinferior', 'threeinferior', 'fourinferior', 'fiveinferior', 'sixinferior', 'seveninferior',
        'eightinferior', 'nineinferior', 'centinferior', 'dollarinferior', 'periodinferior', 'commainferior', 'Agravesmall', 'Aacutesmall',
        'Acircumflexsmall', 'Atildesmall', 'Adieresissmall', 'Aringsmall', 'AEsmall', 'Ccedillasmall', 'Egravesmall', 'Eacutesmall',
        'Ecircumflexsmall', 'Edieresissmall', 'Igravesmall', 'Iacutesmall', 'Icircumflexsmall', 'Idieresissmall', 'Ethsmall', 'Ntildesmall',
        'Ogravesmall', 'Oacutesmall', 'Ocircumflexsmall', 'Otildesmall', 'Odieresissmall', 'OEsmall', 'Oslashsmall', 'Ugravesmall',
        'Uacutesmall', 'Ucircumflexsmall', 'Udieresissmall', 'Yacutesmall', 'Thornsmall', 'Ydieresissmall',
    ];

    /** @var list<string> */
    public const EXPERT_SUBSET_CHARSET = [
        '.notdef', 'space', 'dollaroldstyle', 'dollarsuperior', 'parenleftsuperior', 'parenrightsuperior', 'twodotenleader', 'onedotenleader',
        'comma', 'hyphen', 'period', 'fraction', 'zerooldstyle', 'oneoldstyle', 'twooldstyle', 'threeoldstyle',
        'fouroldstyle', 'fiveoldstyle', 'sixoldstyle', 'sevenoldstyle', 'eightoldstyle', 'nineoldstyle', 'colon', 'semicolon',
        'commasuperior', 'threequartersemdash', 'periodsuperior', 'asuperior', 'bsuperior', 'centsuperior', 'dsuperior', 'esuperior',
        'isuperior', 'lsuperior', 'msuperior', 'nsuperior', 'osuperior', 'rsuperior', 'ssuperior', 'tsuperior',
        'ff', 'fi', 'fl', 'ffi', 'ffl', 'parenleftinferior', 'parenrightinferior', 'hyphensuperior',
        'colonmonetary', 'onefitted', 'rupiah', 'centoldstyle', 'figuredash', 'hypheninferior', 'onequarter', 'onehalf',
        'threequarters', 'oneeighth', 'threeeighths', 'fiveeighths', 'seveneighths', 'onethird', 'twothirds', 'zerosuperior',
        'onesuperior', 'twosuperior', 'threesuperior', 'foursuperior', 'fivesuperior', 'sixsuperior', 'sevensuperior', 'eightsuperior',
        'ninesuperior', 'zeroinferior', 'oneinferior', 'twoinferior', 'threeinferior', 'fourinferior', 'fiveinferior', 'sixinferior',
        'seveninferior', 'eightinferior', 'nineinferior', 'centinferior', 'dollarinferior', 'periodinferior', 'commainferior',
    ];

    /** @var array<int, string> */
    public const STANDARD_ENCODING = [
        32 => 'space', 33 => 'exclam', 34 => 'quotedbl', 35 => 'numbersign', 36 => 'dollar', 37 => 'percent',
        38 => 'ampersand', 39 => 'quoteright', 40 => 'parenleft', 41 => 'parenright', 42 => 'asterisk', 43 => 'plus',
        44 => 'comma', 45 => 'hyphen', 46 => 'period', 47 => 'slash', 48 => 'zero', 49 => 'one',
        50 => 'two', 51 => 'three', 52 => 'four', 53 => 'five', 54 => 'six', 55 => 'seven',
        56 => 'eight', 57 => 'nine', 58 => 'colon', 59 => 'semicolon', 60 => 'less', 61 => 'equal',
        62 => 'greater', 63 => 'question', 64 => 'at', 65 => 'A', 66 => 'B', 67 => 'C',
        68 => 'D', 69 => 'E', 70 => 'F', 71 => 'G', 72 => 'H', 73 => 'I',
        74 => 'J', 75 => 'K', 76 => 'L', 77 => 'M', 78 => 'N', 79 => 'O',
        80 => 'P', 81 => 'Q', 82 => 'R', 83 => 'S', 84 => 'T', 85 => 'U',
        86 => 'V', 87 => 'W', 88 => 'X', 89 => 'Y', 90 => 'Z', 91 => 'bracketleft',
        92 => 'backslash', 93 => 'bracketright', 94 => 'asciicircum', 95 => 'underscore', 96 => 'quoteleft', 97 => 'a',
        98 => 'b', 99 => 'c', 100 => 'd', 101 => 'e', 102 => 'f', 103 => 'g',
        104 => 'h', 105 => 'i', 106 => 'j', 107 => 'k', 108 => 'l', 109 => 'm',
        110 => 'n', 111 => 'o', 112 => 'p', 113 => 'q', 114 => 'r', 115 => 's',
        116 => 't', 117 => 'u', 118 => 'v', 119 => 'w', 120 => 'x', 121 => 'y',
        122 => 'z', 123 => 'braceleft', 124 => 'bar', 125 => 'braceright', 126 => 'asciitilde', 161 => 'exclamdown',
        162 => 'cent', 163 => 'sterling', 164 => 'fraction', 165 => 'yen', 166 => 'florin', 167 => 'section',
        168 => 'currency', 169 => 'quotesingle', 170 => 'quotedblleft', 171 => 'guillemotleft', 172 => 'guilsinglleft', 173 => 'guilsinglright',
        174 => 'fi', 175 => 'fl', 177 => 'endash', 178 => 'dagger', 179 => 'daggerdbl', 180 => 'periodcentered',
        182 => 'paragraph', 183 => 'bullet', 184 => 'quotesinglbase', 185 => 'quotedblbase', 186 => 'quotedblright', 187 => 'guillemotright',
        188 => 'ellipsis', 189 => 'perthousand', 191 => 'questiondown', 193 => 'grave', 194 => 'acute', 195 => 'circumflex',
        196 => 'tilde', 197 => 'macron', 198 => 'breve', 199 => 'dotaccent', 200 => 'dieresis', 202 => 'ring',
        203 => 'cedilla', 205 => 'hungarumlaut', 206 => 'ogonek', 207 => 'caron', 208 => 'emdash', 225 => 'AE',
        227 => 'ordfeminine', 232 => 'Lslash', 233 => 'Oslash', 234 => 'OE', 235 => 'ordmasculine', 241 => 'ae',
        245 => 'dotlessi', 248 => 'lslash', 249 => 'oslash', 250 => 'oe', 251 => 'germandbls',
    ];
}
