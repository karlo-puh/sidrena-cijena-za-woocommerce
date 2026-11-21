=== Sidrena cijena za WooCommerce ===
Contributors: kristijanbiro
Tags: woocommerce, sidrena cijena, dodatna cijena, cjenik, omnibus, najniža cijena, hrvatska
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
WC requires at least: 9.0
WC tested up to: 9.9
Stable tag: 2.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Sidrena (dodatna) cijena uz svaku cijenu, najniža cijena u 30 dana prije sniženja i strojno čitljiv cjenik (CSV) prema NN 132/2026 i Zakonu o zaštiti potrošača.

== Description ==

Dodatak provodi obveze hrvatskih webshopova koje stupaju na snagu **17. studenoga 2026.** (Odluke Vlade RH, NN 101/2026, izmjene NN 110/2026, na temelju Zakona o iznimnim mjerama kontrole cijena, NN 40/2025) te obvezu isticanja najniže cijene u 30 dana (Zakon o zaštiti potrošača, čl. 19):

* **Sidrena (dodatna) cijena** – uz svaku aktualnu cijenu prikazuje se redovna cijena koja je vrijedila na referentni dan (zadano **10. 9. 2026.**; za FMCG kategorije koje ste već označavali može se zadržati **2. 5. 2025.** po kategoriji). Prikaz je jasan, vidljiv i čitljiv, s datumom, na popisu proizvoda, stranici proizvoda, varijacijama, košarici i blagajni (klasični predlošci) te putem podataka Store API-ja za blokovsku košaricu.
* **Najniža cijena u 30 dana prije sniženja** – dodatak bilježi povijest cijena i za vrijeme svakog sniženja prikazuje najnižu cijenu u 30 dana prije početka sniženja te postotak popusta izračunat iz nje.
* **Strojno čitljiv cjenik (CSV)** – zaseban cjenik robe i cjenik usluga, objavljuje se pri promjeni cijena ili ponude (najkasnije do 11:00 na dan promjene), uz dnevnu provjeru (zadano u 06:00) koja objavljuje novu datoteku samo ako se cjenik promijenio. Javne adrese: `/cjenik/`, `/cjenik/latest_roba.csv`, `/cjenik/latest_usluge.csv` (stare `/cjenik/latest.csv` i `/cjenik/latest.xml` vode na cjenik robe). Zamijenjene datoteke dostupne su još najmanje 15 dana.
* **Više prodajnih objekata** – uz webshop možete dodati poslovnice (oblik, adresa, oznaka). Svaki prodajni objekt dobiva vlastiti cjenik s propisanim nazivom i vlastite adrese (`/cjenik/{oznaka}/latest_roba.csv`), ili jedan cjenik „za sva prodajna mjesta”.
* **Alati** – jednim klikom snimite trenutne redovne cijene kao sidrene cijene (akcijske se cijene nikad ne kopiraju), uvezite sidrene cijene iz CSV-a (šifra → cijena), izvezite ih, te zakažite automatsko snimanje za budući datum (npr. „bazna cijena” od 17. 11. 2026.).
* **Generički mehanizam referentnih cijena** – uz sidrenu cijenu možete uključiti i drugu referentnu cijenu (bazna cijena) čim ministarstvo objavi pravilnik.
* WP-CLI naredbe, Action Scheduler, HPOS kompatibilnost, predlošci koje tema može nadjačati, kuke/filtri s prefiksom `scwc_`.

= Što dodatak NE radi =

* Ne ograničava cijene – sidrena cijena je informacija, ne ograničenje.
* Ne šalje ništa državnim tijelima – cjenik se samo objavljuje na vašoj stranici.
* Ne zna cijene koje su vrijedile prije instalacije – sidrene cijene morate snimiti/uvesti sami (v. Alati), a najniža cijena u 30 dana računa se iz povijesti od trenutka instalacije (do tada se, prema postavci, koristi redovna cijena i označava u administraciji).

= Blokovska košarica i blagajna =

U blokovskoj košarici i blagajni (WooCommerce Blocks) sidrena cijena prikazuje se kao dodatni redak podataka stavke; puni prikaz oznake u blokovima planiran je za sljedeću verziju. Klasična košarica/blagajna (shortcode) imaju potpuni prikaz.

= Za razvojne inženjere =

* Funkcije za teme: `sidrena_cijena( $product, $args )`, `scwc_get_reference_price( $product, 'anchor' )`, `scwc_get_lowest_30_day_price( $product )`, `scwc_discount_percent( $product )`.
* Shortcode: `[sidrena_cijena id="123" key="anchor"]`.
* Predložak: kopirajte `templates/price-badge.php` u `vasa-tema/sidrena-cijena/price-badge.php`.
* Filtri: `scwc_reference_price_types`, `scwc_reference_date`, `scwc_should_render_badge`, `scwc_price_badge_html`, `scwc_is_service`, `scwc_product_brand`, `scwc_barcode_meta_keys`, `scwc_price_list_item`, `scwc_price_list_csv_header`, `scwc_price_list_csv_row`.
* Akcije: `scwc_price_changed`, `scwc_price_list_generated`, `scwc_snapshot_completed`, `scwc_import_completed`.

== Usklađenost s NN 132/2026 ==

**Pravilnik o načinu objave cjenika proizvoda (1584)**

* Cjenik u CSV formatu (UTF-8, odvojnik „;”) na vlastitoj stranici, zasebno za robu i za usluge, s propisanim stupcima: naziv, šifra, marka, neto količina, jedinica mjere, maloprodajna cijena, posebni oblik prodaje (da/ne), cijena za jedinicu mjere, barkod, EAN barkod, bazna cijena (usluge: naziv, maloprodajna cijena, posebni oblik prodaje, bazna cijena).
* Promijenjeni cjenik objavljuje se na dan promjene najkasnije do 11:00; ako se cijene i ponuda nisu promijenile, nova datoteka se ne objavljuje. Promjene spremljene u WooCommerceu objavljuju se nekoliko minuta nakon spremanja (postavka „Objava pri promjeni proizvoda”); dnevna provjera (zadano 06:00) hvata promjene napravljene mimo WooCommercea.
* Naziv datoteke: OIB ili matični broj, oblik, adresa i oznaka prodajnog objekta (ili ZA_SVA_PRODAJNA_MJESTA), ROBA ili USLUGE, broj pohrane (redni broj objave) te datum i vrijeme objave (GGGGMMDD_SSMM). Zamijenjena datoteka ostaje dostupna najmanje 15 dana nakon zamjene.
* Datoteke su dostupne bez prijave i zaštite od robota, s CORS zaglavljima, `no-cache` na `latest_*.csv`, `index.json` za otkrivanje te izravnim adresama datoteka koje ne ovise o postavkama trajnih veza.

**Pravilnik o isticanju bazne cijene (1583)**

* Bazna cijena je redovna cijena na referentni dan (zadano 10. 9. 2026.), nikad akcijska; za proizvod koji tada nije bio u ponudi – cijena na dan prve ponude (dodatak je bilježi automatski za nove proizvode). Snimanje cijena preskače proizvode stvorene nakon referentnog dana.

**Odluka o isticanju dodatne cijene (1212)**

* t. II. – sidrena (dodatna) cijena, uvijek redovna cijena bez akcija, prikazuje se uz svaku cijenu na popisu proizvoda, stranici proizvoda, varijacijama, u košarici, mini-košarici i na blagajni (klasični predlošci) te kao redak podataka stavke u blokovskoj košarici; za vlastite bannere i oglase na stranici koristite `[sidrena_cijena]`.
* t. IV. – za FMCG kategorije koje ste već označavali od 2. 5. 2025. odaberite kategorije u postavkama (datum 2. 5. 2025. ne može se automatski utvrditi).

Što dodatak ne može napraviti umjesto vas: cijene u fizičkim poslovnicama (koriste se cijene iz WooCommercea), letke/plakate/vanjske oglase, e-mailove i stranice narudžbe nakon kupnje (namjerno bez oznake), te provjeru iznosa koje sami uvezete CSV-om.

== Installation ==

1. Prenesite ZIP dodatka (Dodaci → Dodaj novi → Prenesi dodatak) ili mapu u `wp-content/plugins/`. Composer nije potreban.
2. Aktivirajte dodatak. WooCommerce 9.0+ i PHP 8.1+ su obavezni.
3. **WooCommerce → Sidrena cijena → Prodajni objekti**: unesite adresu i oznaku prodajnog objekta webshopa, a u kartici **Cjenik** OIB / matični broj trgovca (ulaze u naziv datoteke cjenika) te dodajte fizičke poslovnice ako ih imate – svaka dobiva vlastitu datoteku i adresu.
4. **Referentne cijene**: provjerite datum (10. 9. 2026.) i po potrebi dodajte FMCG kategorije s datumom 2. 5. 2025.
5. **WooCommerce → Sidrena cijena – Alati**: uvezite provjerene povijesne cijene s pripadajućim datumom. Današnje cijene nemojte označiti povijesnim datumom bez dokaza.
6. **Cjenik**: kliknite „Generiraj sada”, pričekajte dovršetak zakazanih radnji i provjerite `https://vasa-trgovina.hr/cjenik/` i `/cjenik/latest_roba.csv`.
7. Preporuka: postavite pravi sistemski cron (`DISABLE_WP_CRON` + `wp cron event run --due-now` ili `wp action-scheduler run`) kako bi dnevna provjera (06:00) i objava do 11:00 bile pouzdane. Dodatno: vanjski cron koji zakazuje izvoz pozivom na `https://vasa-trgovina.hr/cjenik/?scwc_run=1&key=VAŠ_KLJUČ` (ključ je u postavkama). HTTP poziv vraća 202; Action Scheduler ili WP-Cron i dalje mora izvršiti zakazane radnje.
8. Ako koristite cache dodatak ili CDN: URL-ovi `/cjenik/`, `/cjenik/latest_*.csv` i `/cjenik/index.json` ne smiju se keširati (dodatak šalje `no-cache` zaglavlja i `DONOTCACHEPAGE`).

== Frequently Asked Questions ==

= Kako ažuriram dodatak? =

Kad na GitHubu objavimo novu verziju, WordPress → Dodaci prikazuje „Ažuriraj sada” kao i za svaki drugi dodatak (provjera se radi najviše svakih 12 sati; „Provjeri ponovno” na stranici Ažuriranja osvježava odmah). Ručno: prenesite novi ZIP i odaberite „Zamijeni trenutni prenesenim” – mapa dodatka mora se zvati `sidrena-cijena-za-woocommerce`.

= Otvorio sam /cijene i dobio 404 =

Javna adresa cjenika je `https://vasa-trgovina.hr/cjenik/` (te `/cjenik/latest_roba.csv` i `/cjenik/latest_usluge.csv`), a ne `/cijene`. Slug mijenjate u WooCommerce → Sidrena cijena → Cjenik. Ako i `/cjenik/` vraća 404: otvorite Postavke → Trajne veze i kliknite „Spremi promjene” (bez izmjena) ili u kartici Cjenik kliknite „Ponovno zakaži zadatke”; s „običnim” trajnim vezama koristite `?scwc_cjenik=latest&scwc_format=csv&scwc_kind=roba`. Kartica Cjenik prikazuje rezultat automatske provjere dostupnosti.

= Što je sidrena cijena? =

Službeno „dodatna maloprodajna cijena” – redovna cijena (bez posebnih oblika prodaje) koja je za proizvod/uslugu vrijedila na dan 10. 9. 2026. Mora se istaknuti uz svaku aktualnu cijenu i svako oglašavanje cijene.

= Proizvod je uveden nakon 10. 9. 2026. =

Za novi proizvod ili varijaciju stvorenu dok je fork aktivan dodatak pri prvoj objavi sprema prvu redovnu cijenu i datum. Postojeće reference i oznake „nema referentne cijene” ne mijenja. Ako podatak nedostaje, unesite provjerenu početnu cijenu i stvarni datum; današnja cijena nije dokaz povijesne cijene.

= Moram li čuvati stare cjenike? =

Da, zamijenjeni cjenik mora ostati dostupan najmanje 15 dana nakon zamjene (NN 132/2026, čl. 3(6)). Dodatak čuva i javno poslužuje verzije prema postavci zadržavanja (min. 15 dana), važeći cjenik nikad ne briše, a datoteke ne briše ni pri deaktivaciji.

= Kako se računa postotak popusta? =

Iz najniže cijene u 30 dana prije početka sniženja (a ne iz redovne cijene), zaokruženo na niže.

Ako ta najniža cijena nije viša od trenutačne cijene, nema stvarnog sniženja: dodatak tada ne prikazuje precrtanu cijenu ni postotak, a najniža cijena u 30 dana i dalje je istaknuta.

Ako se cijena na akciji tijekom akcije povisi, 30 dana prije povišenja uključuje nižu akcijsku cijenu, pa se najniža cijena računa iznova od trenutka povišenja (povišenje se ne prikazuje kao sniženje). Postupna sniženja zadržavaju najnižu cijenu prije prvog sniženja.

= Kako se prikazuju varijabilni proizvodi? =

Zadano raspon cijena varijacija; precrtana cijena, najniža cijena u 30 dana i postotak prikazuju se kad kupac odabere varijantu. Uz postavku „Varijabilni proizvod prikaži kao jednu varijantu” popis proizvoda i stranica proizvoda prikazuju jednu varijantu – među varijantama na zalihi (ako nijedna nije na zalihi, među svima) onu s najvećim stvarnim sniženjem u odnosu na njezinu najnižu cijenu u 30 dana, a ako nijedna nije snižena, najjeftiniju (kod jednakog sniženja niža cijena) – s njezinom cijenom, precrtanom najnižom cijenom i sidrenom cijenom. Teme je mogu dohvatiti funkcijom `scwc_featured_variation( $product )` (npr. za sliku proizvoda ili zadanu varijantu).

Postavka je namijenjena temama koje pri odabiru varijante zamijene glavnu cijenu proizvoda cijenom odabrane varijante (npr. WoodMart). S klasičnim WooCommerce predloškom koji cijenu odabrane varijante prikazuje ispod glavne, i s blokovskim predloškom proizvoda (blok „Product Price”), ostavite raspon.

== Changelog ==

= 2.0.0 =
* Cjenik prema Pravilniku o načinu objave cjenika proizvoda (NN 132/2026): samo CSV, zaseban cjenik robe i usluga, novi stupci i nazivi datoteka (OIB/MB, ROBA/USLUGE, broj pohrane, GGGGMMDD_SSMM). Nove postavke: OIB / matični broj trgovca (obavezno; dok nije upisan, novi cjenik se ne objavljuje i administracija prikazuje grešku) i „Isti cjenik za sva prodajna mjesta”. XML se više ne generira; postojeće datoteke ostaju dostupne dok ne isteknu.
* Nova datoteka objavljuje se samo kad se cjenik promijeni. Spremanje proizvoda označava samo taj proizvod, pa se pri promjeni ponovno izrađuju samo njegovi redci umjesto cijelog kataloga; cijeli katalog čita se jednom dnevno i nakon skupnih promjena. Rok objave promjena je 11:00.
* Zamijenjene datoteke brišu se 15 dana (postavka) nakon zamjene; trenutačni cjenik se nikad ne briše.
* Nove adrese `/cjenik/latest_roba.csv` i `/cjenik/latest_usluge.csv`; `/cjenik/latest.csv` i `/cjenik/latest.xml` vode na cjenik robe.
* Snimanje cijena više ne označava proizvode stvorene nakon referentnog dana kao „nema referentne cijene” – njihova je bazna cijena cijena prve ponude.
* Uklonjene postavke: formati, odvojnik CSV-a, porez u cjeniku (cijene su uvijek s PDV-om), zadani naziv posebnog oblika prodaje, broj pohrane prodajnog objekta, polje „Naziv posebnog oblika prodaje” na proizvodu.

= 1.5.0 =
* Nova postavka „Prikaz najniže cijene u 30 dana” (zadano: zaseban redak, kao dosad). „Oblačić (?) uz precrtanu cijenu”: iznad cijene redak s precrtanom najnižom cijenom u 30 dana, postotkom (ako je uključen) i (?) koji dodirom ili prelaskom mišem prikazuje najnižu cijenu u 30 dana. Radi s tipkovnicom i čitačima zaslona; dodir na (?) ne otvara proizvod.
* Oblačić se koristi samo kad je precrtana cijena najniža cijena u 30 dana; inače ostaje zaseban redak.
* Predložak oblačića može se prilagoditi u temi (sidrena-cijena/price-tooltip.php).

= 1.4.2 =
* Ispravak: dodatno sniženje tijekom akcije zadržava najnižu cijenu u 30 dana samo ako je prethodna akcijska cijena bila stvarno sniženje. Ako nije (npr. akcijska cijena povišena nakon jeftinije akcije), novo sniženje je prvo stvarno sniženje: dobiva najnižu cijenu u 30 dana prije sebe i prikazuje precrtanu cijenu i postotak.
* Već spremljene vrijednosti ne preračunavaju se automatski.

= 1.4.1 =
* Besplatni proizvodi: redovna cijena 0 € je i sidrena cijena 0 € – snimanje cijena je zapisuje, CSV uvoz je prihvaća, a kupac vidi „Cijena na dan …: 0,00 €” (cjenik: 0.00). „Nema referentne cijene” ostaje za proizvode koji se na referentni dan nisu prodavali.
* Nova provjera: broj objavljenih proizvoda s redovnom cijenom 0 € i poveznica na popis tih proizvoda (obavijest koju možete sakriti i redak u Alatima), da se zaboravljena cijena ne zapiše kao sidrena cijena 0 €.
* Već spremljene sidrene cijene 0 € (npr. ručni unos) od ove verzije se prikazuju i izvoze.

= 1.4.0 =
* Kad najniža cijena u 30 dana prije sniženja nije viša od trenutačne cijene, nema stvarnog sniženja: bez precrtane cijene i postotka (najniža cijena i dalje je istaknuta).
* Precrtana cijena i postotak uvijek se računaju od najniže cijene u 30 dana, i kad je ona viša od trenutačne redovne cijene.
* Ispravak: nova akcijska cijena više ne može postati vlastita najniža cijena u 30 dana (početak akcije je trenutak zapisa promjene cijene).
* Povišena cijena tijekom akcije dobiva novu najnižu cijenu u 30 dana, pa se povišenje ne prikazuje kao sniženje. Postupna sniženja zadržavaju najnižu cijenu prije prvog sniženja.
* Košarica, mini-košarica i blagajna više ne preoblikuju prikaz cijene; iznos stavke uvijek ostaje WooCommerceov.
* Nova postavka (zadano isključena) „Varijabilni proizvod prikaži kao jednu varijantu” i funkcija `scwc_featured_variation()` za teme.

= 1.3.1 =
* „Provjeri ponovno” na WordPress stranici Ažuriranja sada osvježava i GitHub izdanje, uključujući prethodno neuspjelu provjeru.
* Redovite provjere i dalje koriste predmemoriju; nema novih postavki ni promjena u izvozu cjenika.
* Pri prvom prijelazu s verzije 1.2.2 ili 1.3.0 može biti potrebno pričekati istek stare predmemorije (do 12 sati) i sljedeću provjeru ažuriranja.

= 1.3.0 =
* Veliki cjenici obrađuju se u manjim radnjama kroz postojeći Action Scheduler, s napretkom i redcima na disku.
* Prekinuti poslovi nastavljaju od potvrđenog koraka; pogreške se prijavljuju, a posljednji dovršeni cjenik ostaje dostupan.
* CSV/XML objavljuju se zajedno nakon dovršetka svih datoteka; datotečna brava sprečava istodobno pisanje.
* Ispravljeni su tokeni CSV uvoza s velikim slovima i otkazivanje starih zadataka s argumentima.
* Ručno i HTTP pokretanje potvrđuje zakazivanje (HTTP 202), a ne dovršetak. WP-CLI izvoz ostaje sinkron.
* Postojeće postavke i povijesne cijene ostaju sačuvane. Za dnevni cjenik robe odaberite „Nikad (samo dnevno)” i zatim „Ponovno zakaži zadatke”.

= 1.2.2 =
* Automatski sprema prvu redovnu cijenu i datum za nove proizvode i varijacije pri objavi.
* Ažuriranja ovog forka preuzimaju se iz karlo-puh/sidrena-cijena-za-woocommerce.

= 1.2.1 =
* Dodatak više ne ovisi o Composeru: radi iz bilo kojeg ZIP-a (izdanje, GitHub „Source code” ili git clone). Uklonjena poruka o nedostajućem vendor/autoload.php.

= 1.2.0 =
* Ažuriranja iz GitHub Releases: WordPress prikazuje „Ažuriraj sada” kad postoji novija verzija; pogled „Detalji” pokazuje bilješke izdanja. Privatni repozitorij zahtijeva token putem filtra `scwc_github_token`.

= 1.1.3 =
* Licenca promijenjena u MIT; projekt objavljen na GitHubu s README-om i CI provjerama.
* Polja za datum i vrijeme u postavkama dovoljno su široka da prikažu cijelu vrijednost.

= 1.1.2 =
* Korisnički priručnik (PDF) uz dodatak. Kartica Cjenik jasno prikazuje javnu adresu (/cjenik/) i automatski provjerava dostupnost; gumb „Ponovno zakaži zadatke” obnavlja i rute; rute se samoobnavljaju ako ih drugi dodatak obriše; obavijest s javnom adresom do prvog generiranja.

= 1.1.1 =
* Provjera usklađenosti s NN 101/2026: upozorenje kad je generiranje zakazano nakon 07:30; promjene prije 08:00 objavljuju se odmah; skriveni proizvodi i proizvodi drugih vrsta (paketi, pretplate) ulaze u cjenik; mini-košarica i osvježavanje pri svakoj promjeni cijene uključeni zadano; trajni ključevi prodajnih objekata (preimenovanje ne gubi arhivu); promjene otkrivene noćnom provjerom također osvježavaju cjenik; izravne adrese datoteka u index.json.

= 1.1.0 =
* Više prodajnih objekata: svaka poslovnica ima vlastitu datoteku cjenika, arhivu i URL (`/cjenik/{oznaka}/latest.xml`); `/cjenik/latest.xml` i dalje poslužuje webshop.
* index.json navodi sve prodajne objekte.

= 1.0.0 =
* Prva verzija: sidrena cijena, povijest cijena i najniža cijena u 30 dana, XML/CSV cjenik s javnim URL-om i zadržavanjem, alati za snimanje/uvoz/izvoz, WP-CLI, Store API.
