# Sidrena cijena za WooCommerce

## Održavani fork

Ovo je fork projekta [Kristijana Biroa](https://github.com/kbiro-nander/sidrena-cijena-za-woocommerce), pod izvornom MIT licencom. Verzija **1.2.2** dodaje automatsku sidrenu cijenu za nove artikle i preusmjerava ugrađeni updater na [izdanja ovog repozitorija](https://github.com/karlo-puh/sidrena-cijena-za-woocommerce/releases).

- Za proizvode stvorene dok je ova verzija aktivna, prva objava s cijenom sprema redovnu cijenu i datum te objave u vremenskoj zoni trgovine. Nacrti čekaju objavu; varijacije čekaju i objavljen roditeljski proizvod.
- Koriste se standardni WordPress/WooCommerce hookovi: ručni unos, WooCommerce CSV uvoz i CRUD/API spremanje. Izravni upisi u bazu nisu podržani.
- Postojeće ručno unesene/uvezene reference i oznaka „nema referentne cijene” ostaju sačuvane. Kasnije promjene cijene ne mijenjaju sidrenu. Duplicirani proizvodi s kopiranom referencom također zadržavaju tu referencu; provjerite je ako kopiju koristite za drugi artikl.
- Postojeći artikli bez sidrene (uključujući ranije stvorene nacrte) ne popunjavaju se današnjom cijenom. Za njih unesite ili uvezite provjerenu početnu cijenu i stvarni datum. Plugin mora biti aktivan tijekom stvaranja i prve objave novih artikala.
- Početno prijeđite na fork prijenosom ZIP-a iz Releases preko **Dodaci → Dodaj novi → Prenesi dodatak → Zamijeni postojeću verziju**. Ne brišite prethodni plugin. Direktorij i ključevi podataka ostaju isti. Sljedeća ažuriranja dolaze s ovog forka.

### Verzija 1.3.0: pouzdanije generiranje velikih cjenika

Postavke i postojeće referentne cijene ostaju sačuvane pri nadogradnji s 1.2.2.

- Generiranje iz administracije i vanjskog cron URL-a ulazi u postojeći Action Scheduler (WP-Cron je pričuvni mehanizam). Jedna radnja obrađuje do 100 proizvoda/varijacija, s predajom kontrole nakon približno 10 sekundi između proizvoda. Jedan spor poziv dodatka ili baze i dalje može trajati dulje.
- Redci i položaj obrade spremaju se na disk. Prekinuta radnja nastavlja od zadnjeg potvrđenog položaja; pripremljeni redci ne skupljaju se u velikom PHP polju. Svaki izlazni format/objekt zatim dobiva zasebnu radnju koja čita te redke bez ponovnog učitavanja proizvoda.
- CSV i XML postaju dostupni tek kada su svi izlazi dovršeni. Neuspjeh čuva posljednji objavljeni cjenik i prijavljuje se u Zakazanim radnjama. Prekinuti proces ima zakazan pokušaj nastavka za pet minuta; poznata greška ne ponavlja se beskonačno. Nakon uklanjanja uzroka, „Generiraj sada” nastavlja posao.
- Brava je vezana uz proces i zajednički direktorij cjenika, pa je čišćenje Redisa ne može ukloniti. Više web poslužitelja mora dijeliti taj direktorij i podržavati datotečne brave.
- „Generiraj sada” potvrđuje zakazivanje. Vanjski URL vraća HTTP **202** i `{"ok":true,"queued":true}`; to još nije potvrda objave. Queue runner mora ostati aktivan. `wp scwc export` ostaje sinkron.
- Ispravljeno je otkazivanje zadataka s argumentima (npr. razlog „change”): ponovno zakazivanje uklanja i stare dodatne izvoze, uz očuvanje tuđih zadataka.
- Ispravljen je uvoz čiji token pregleda sadrži velika slova, uključujući Redis. Postojeće sidrene cijene i način bilježenja povijesti ostaju sačuvani.

Za velike uvoze proizvoda koristite [ugrađeni WooCommerce CSV uvoz/izvoz](https://woocommerce.com/document/product-csv-importer-exporter/). Postojeći test provjerava da njegov stvarni CRUD put hvata prvu cijenu novih proizvoda i čuva postojeću referencu. Alati dodatka za uvoz povijesnih referenci ostaju zaseban, sinkroni alat; ova izmjena ih ne pretvara u novi sustav masovnog uvoza.

[WP All Import](https://www.wpallimport.com/documentation/import-processing/) također dijeli posao na manje iteracije. Preuzet je taj pristup, bez nove ovisnosti: [Action Scheduler](https://actionscheduler.org/perf/) već dolazi uz WooCommerce. WooCommerceov CSV i WordPressov izvoz sadržaja ne proizvode ovaj XML/CSV cjenik s prodajnim objektima i arhivom.

Provjere: `composer test`, `composer phpstan`, `vendor/bin/phpcs`. Za izolirani WordPress/WooCommerce/Redis test: `bash bin/test-integration-docker.sh` (Docker; na Colimi po potrebi `DOCKER_CONTEXT=colima`). Skripta koristi isključivo testne podatke, uklanja svoje kontejnere i volumen te ne izrađuje ZIP. Za provjeru gotovog paketa proslijedite njegovu putanju kao prvi argument. Obuhvaća stvarni red zadataka, odvojene PHP procese, Redis i zapis/čitanje 100.000 redaka s ograničenom memorijom.

Dokumentacija ispod opisuje postavljanje i ostale funkcionalnosti.

> **Napomena: ovaj projekt je „vibe coded”** – razvijen uz pomoć umjetne inteligencije (Claude) prema tekstu propisa, pokriven automatiziranim testovima i isproban na stvarnom webshopu, ali **nije ga pregledao pravnik**. Koristite ga **isključivo na vlastitu odgovornost**: prije 17. 11. 2026. provjerite ga na vlastitoj trgovini, usporedite izlaz s propisima i po potrebi se posavjetujte s pravnim savjetnikom. Autor ne odgovara za bilo kakvu štetu, kaznu ili trošak (v. [Licenca i odricanje od odgovornosti](#licenca-i-odricanje-od-odgovornosti)).

WordPress/WooCommerce dodatak koji pomaže hrvatskim webshopovima s prikazom sidrene cijene i objavom cjenika prema odlukama koje stupaju na snagu **17. studenoga 2026.** te s odvojenom obvezom prikaza najniže cijene prije sniženja:

- **sidrena (dodatna) cijena** uz svaku cijenu – redovna cijena koja je vrijedila 10. 9. 2026. (za ranije obuhvaćene FMCG proizvode ostaje 2. 5. 2025.),
- **najniža cijena u 30 dana prije sniženja** i postotak popusta izračunat iz nje (Zakon o zaštiti potrošača, čl. 19),
- **strojno čitljiv cjenik (XML/CSV)** na javnoj adresi `/cjenik/` (zadana putanja; mijenja se u postavkama, a stvarna adresa vaše trgovine piše u kartici Cjenik), s propisanim poljima i nazivom datoteke, za robu ažuriran jednom dnevno do 08:00 za tekući radni dan; usluge imaju zasebno pravilo ažuriranja pri promjeni, s arhivom od najmanje 30 dana i **zasebnom datotekom za svaki prodajni objekt** (webshop i poslovnice).

Pravna osnova: [Odluka o isticanju dodatne cijene](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_101_1212.html) i [Odluka o objavi cjenika proizvoda i usluga](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_101_1213.html) (NN 101/2026), uz odgodu stupanja na snagu na 17. 11. 2026. ([NN 110/2026, 1309](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_110_1309.html) i [1310](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_110_1310.html)). Referentni datum sidrene cijene ostaje 10. 9. 2026.; odgoda ga ne mijenja.

### Jedan dnevni izvoz robe

[Ministarstvo gospodarstva, pojašnjenje 2.3](https://mingo.gov.hr/UserDocsImages/slike/MINGO_Poja%C5%A1njenja_dodatna%20cijena_objava%20cjenika.pdf) izričito navodi da promjene cijena robe tijekom dana ne zahtijevaju novu dnevnu datoteku. Dostupnost za automatizirano preuzimanje nije zahtjev za regeneriranje pri svakom spremanju proizvoda. Pojašnjenje još navodi izvorni datum primjene; kasnije izmjene NN 110/2026 imaju prednost.

Za katalog robe: **Cjenik → Regeneriraj pri promjeni cijene → Nikad (samo dnevno)**. Nakon spremanja kliknite **Ponovno zakaži zadatke** kako biste uklonili ranije zakazane dodatne izvoze. Dnevno generiranje u 06:00, ručno pokretanje, snimanje prve cijene i povijest cijena ostaju uključeni. Manje radnje u redu zadataka sastavljaju **isti dnevni cjenik**, a ne objavljuju novi cjenik za svaki proizvod.

Postojeći zadani način „Svi proizvodi” stvara dodatne izvoze i nije zakonski potreban za robu. Ova izmjena ne prepisuje spremljene postavke ni zadane vrijednosti; način rada treba izabrati u postojećoj postavci. Za usluge provjerite zasebno pravilo iz točke II. Odluke i pojašnjenja 2.3. Povijesne sidrene cijene ne smiju se zamijeniti današnjima.

## Značajke

- Oznaka sidrene cijene na popisu proizvoda, stranici proizvoda, varijacijama, košarici, mini-košarici i blagajni; podaci za blokovsku košaricu putem Store API-ja; shortcode `[sidrena_cijena]` za bannere.
- Povijest cijena i najniža cijena u 30 dana; referenca ostaje zamrznuta tijekom postupnih sniženja.
- XML i CSV cjenik s poljima naziv, šifra, marka, jedinica mjere, cijena za jedinicu mjere, maloprodajna cijena + posebni oblik prodaje, sidrena cijena, barkod, dostupnost; usluge s vlastitim skupom polja.
- Jedna datoteka po prodajnom objektu, naziv `oblik_adresa_oznaka_brojpohrane_YYYYMMDD_HHMMSS.xml`, javne adrese `/cjenik/`, `/cjenik/latest.xml`, `/cjenik/{oznaka}/latest.xml`, `/cjenik/index.json`.
- Alati: snimanje trenutnih redovnih cijena kao sidrenih (akcijske se nikad ne kopiraju), CSV uvoz/izvoz, automatsko snimanje na budući datum (npr. „bazna cijena” od 17. 11. 2026.).
- Action Scheduler ili WP-Cron, vanjski cron URL, WP-CLI naredbe (`wp scwc …`), HPOS kompatibilnost, predlošci koje tema može nadjačati, kuke s prefiksom `scwc_`.

## Snimke zaslona

| Postavke – Cjenik (javna adresa, provjera dostupnosti, stanje) | Postavke – Referentne cijene |
|---|---|
| ![Kartica Cjenik](docs/manual/img/postavke-cjenik.png) | ![Kartica Referentne cijene](docs/manual/img/postavke-referentne-cijene.png) |

| Postavke – Prikaz (s pregledom oznake) | Alati (snimanje, CSV uvoz/izvoz) |
|---|---|
| ![Kartica Prikaz](docs/manual/img/postavke-prikaz.png) | ![Alati](docs/manual/img/alati.png) |

Sve kartice (Prodajni objekti, Povijest cijena, Napredno) prikazane su u [priručniku](docs/manual/Sidrena-cijena-za-WooCommerce-prirucnik.pdf).

## Zahtjevi

WordPress 6.4+, WooCommerce 9.0+, PHP 8.1+.

## Instalacija

**Iz ZIP-a (preporučeno):** preuzmite `sidrena-cijena-za-woocommerce-x.y.z.zip` s [Releases](../../releases) stranice (radi i GitHubov „Source code (zip)”) i prenesite ga u WordPress → Dodaci → Dodaj novi → Prenesi dodatak. ZIP sadrži i priručnik `prirucnik.pdf`.

**Iz izvornog koda:**

```bash
git clone https://github.com/kbiro-nander/sidrena-cijena-za-woocommerce.git wp-content/plugins/sidrena-cijena-za-woocommerce
# Composer nije potreban za rad dodatka (ugrađeni autoloader); za razvoj i testove: composer install
# ZIP za prijenos: bin/build-zip.sh   # → dist/sidrena-cijena-za-woocommerce-x.y.z.zip
```

## Ažuriranje

Dodatak provjerava [GitHub Releases](../../releases) i WordPress prikazuje uobičajeni gumb „Ažuriraj sada” kad postoji novija verzija. Od verzije **1.3.1**, **Nadzorna ploča → Ažuriranja → Provjeri ponovno** osvježava i GitHub podatke; redovite provjere i dalje koriste predmemoriju do 12 sati. Pri prvom prijelazu s 1.2.2 ili 1.3.0 može biti potrebno pričekati istek stare predmemorije i sljedeću provjeru. Ručno ažuriranje: prenesite novi ZIP i odaberite „Zamijeni trenutni prenesenim”.

## Brzi start

1. Aktivirajte dodatak.
2. **WooCommerce → Sidrena cijena → Prodajni objekti**: unesite adresu, oznaku i broj pohrane webshopa; dodajte poslovnice ako ih imate.
3. **Referentne cijene**: provjerite datum 10. 9. 2026.; po potrebi dodajte FMCG kategorije s datumom 2. 5. 2025.
4. **WooCommerce → Sidrena cijena – Alati**: uvezite provjerene povijesne cijene s pripadajućim datumom. Današnje redovne cijene nemojte označiti kao cijene od 10. 9. 2026.
5. **Cjenik**: kliknite „Generiraj sada”, pričekajte dovršetak zakazanih radnji i otvorite adresu prikazanu u statusnom okviru (zadano `https://vasa-trgovina.hr/cjenik/`; ako ste promijenili putanju, npr. u `cijene`, adresa je `/cijene/`).
6. Postavite pravi sistemski cron (v. priručnik, poglavlje 7).

## Dokumentacija

- **Priručnik za vlasnike trgovine (PDF):** [`docs/manual/Sidrena-cijena-za-WooCommerce-prirucnik.pdf`](docs/manual/Sidrena-cijena-za-WooCommerce-prirucnik.pdf) – postavke polje po polje, alati, javni cjenik, automatika, kontrolna lista usklađenosti, rješavanje problema.
- **`readme.txt`** – WordPress.org opis, tablica usklađenosti s NN 101/2026, česta pitanja.
- **Specifikacija dizajna:** `docs/superpowers/specs/2026-09-18-sidrena-cijena-design.md`.

## Razvoj

```bash
composer install
composer test            # PHPUnit (Brain Monkey, bez WordPress instalacije)
composer phpstan         # PHPStan razina 6 sa WordPress/WooCommerce stubovima
vendor/bin/phpcs         # WordPress Coding Standards
bin/make-pot.sh          # languages/*.pot
bin/build-manual.sh      # docs/manual/*.pdf (WeasyPrint)
bin/build-zip.sh         # dist/*.zip
```

Struktura: `src/` (PSR-4, `SidrenaCijena\`), `templates/` (nadjačivi predlošci), `assets/`, `languages/`, `tests/` (jedinični testovi), `docs/`, `bin/`. Jedinični testovi rade bez WordPress instalacije; integracijski testovi koriste izolirani WordPress, WooCommerce i Redis. Prije objave potrebna je i provjera na testnoj (staging) stranici.

## Doprinosi

Prijave grešaka i pull requestovi su dobrodošli. Prije slanja pokrenite `composer test`, `composer phpstan` i `vendor/bin/phpcs`. Za tumačenje propisa obratite se pravnom savjetniku – rasprave o pravnom tumačenju otvorite kao issue s poveznicom na izvor.

## Licenca i odricanje od odgovornosti

Objavljeno pod [MIT licencom](LICENSE): dodatak smijete slobodno koristiti, mijenjati i dalje distribuirati, uključujući u komercijalne svrhe, uz zadržavanje obavijesti o autorskim pravima.

Softver se isporučuje **„kakav jest”, bez ikakvog jamstva**. Autor ne odgovara za bilo kakvu štetu, kaznu ili trošak proizašao iz korištenja dodatka, uključujući odluke inspekcijskih tijela. Dodatak i priručnik **nisu pravni savjet**; za usklađenost vaše trgovine odgovorni ste sami i preporučujemo provjeru s pravnim savjetnikom.

Autor: Kristijan Biro.
