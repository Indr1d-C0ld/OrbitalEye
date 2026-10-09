<?php

/**
 * Coda di revisione delle pubblicazioni Telegram (vedi schema.sql).
 *
 * Con una chat di revisione configurata (Impostazioni), una condivisione
 * non va subito sul canale: le immagini già composte vengono salvate in
 * storage/publications/ e inviate alla chat di revisione, dove chi deve
 * approvare le vede esattamente come usciranno. L'approvazione — o lo scarto
 * — si fa dalla piattaforma (publications.php): solo allora lo stesso
 * contenuto, gli stessi file, parte verso il canale. Il bot non riceve
 * aggiornamenti: nella chat di revisione c'è solo un link alla coda.
 */
final class Publication
{
    private const DIR = 'publications';

    /** Dopo quanto una presa in carico rimasta a metà (processo interrotto
     * durante l'invio) torna decidibile. */
    private const STALE_CLAIM = '-10 minutes';

    /**
     * Invio alla chat di revisione: un messaggio di intestazione con il link
     * alla coda, poi il contenuto in risposta, con la didascalia identica a
     * quella che andrà sul canale (un'intestazione dentro la didascalia la
     * allungava e, vicino al limite di Telegram, la tagliava).
     * @return array{first:?int, warnings:string[]} first = id dell'intestazione
     */
    public static function sendForReview(TelegramClient $review, array $pub): array
    {
        $url = self::queueUrl((int) $pub['id']);
        $header = $review->sendMessage(
            "🕵 DA APPROVARE · pubblicazione #{$pub['id']}" . ($pub['summary'] ? ' — ' . $pub['summary'] : '')
                . ($url ? '' : "\nApprova o scarta dalla pagina Pubblicazioni di OrbitalEye."),
            null,
            $url ? [['✅ Approva o ✖ scarta in OrbitalEye', $url]] : []
        );
        $headerId = isset($header['message_id']) ? (int) $header['message_id'] : null;
        $sent = self::send($review, self::payload($pub), (string) $pub['caption'], $headerId);
        return ['first' => $headerId, 'warnings' => $sent['warnings']];
    }

    /**
     * Salva una pubblicazione preparata da PublicationBuilder e la mette in
     * attesa. @return int id
     */
    public static function enqueue(array $built): int
    {
        $db = Database::get();
        $db->prepare(
            'INSERT INTO publications (study_id, kind, ref_id, summary, caption, format, media_json)
             VALUES (:sid, :kind, :ref, :summary, :caption, :format, :media)'
        )->execute([
            ':sid' => $built['study_id'], ':kind' => $built['kind'], ':ref' => $built['ref_id'],
            ':summary' => $built['summary'], ':caption' => $built['caption'], ':format' => $built['format'],
            ':media' => '[]',
        ]);
        $id = (int) $db->lastInsertId();
        $written = [];
        $store = function (array $item, string $suffix) use ($id, &$written): array {
            [$bytes, $name, $mime] = $item;
            $ext = pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg';
            $rel = self::DIR . '/' . $id . '_' . $suffix . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (file_put_contents(Config::storageRoot() . '/' . $rel, $bytes) === false) {
                throw new RuntimeException('Impossibile salvare l\'immagine da rivedere (storage/publications non scrivibile?).');
            }
            $written[] = $rel;
            return ['path' => $rel, 'name' => $name, 'mime' => $mime];
        };
        try {
            $media = [];
            foreach ($built['media'] as $i => $item) {
                $media[] = $store($item, 'm' . $i);
            }
            // Stessa immagine (formati striscia e solo immagine): un solo file.
            $doc = !$built['document'] ? null : ($built['document'][0] === $built['media'][0][0]
                ? ['path' => $media[0]['path'], 'name' => $built['document'][1], 'mime' => $built['document'][2]]
                : $store($built['document'], 'doc'));
        } catch (Throwable $e) {
            foreach ($written as $rel) {
                @unlink(Config::storageRoot() . '/' . $rel);
            }
            Database::get()->prepare('DELETE FROM publications WHERE id = :id')->execute([':id' => $id]);
            throw $e;
        }
        $db->prepare('UPDATE publications SET media_json = :m, document_json = :d WHERE id = :id')
            ->execute([':m' => json_encode($media), ':d' => $doc ? json_encode($doc) : null, ':id' => $id]);
        return $id;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM publications WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? self::decode($row) : null;
    }

    /** In attesa prima, poi le più recenti. */
    public static function recent(int $limit = 50): array
    {
        $stmt = Database::get()->prepare(
            "SELECT p.*, s.title AS study_title FROM publications p LEFT JOIN studies s ON s.id = p.study_id
             ORDER BY (p.status IN ('pending', 'sending')) DESC, p.id DESC LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'decode'], $stmt->fetchAll());
    }

    public static function pendingCount(): int
    {
        try {
            return (int) Database::get()->query("SELECT COUNT(*) FROM publications WHERE status IN ('pending', 'sending')")->fetchColumn();
        } catch (Throwable $e) {
            return 0; // tabella non ancora creata: lo schema si allinea al primo accesso
        }
    }

    /** Contenuto pronto per l'invio: [media => [[byte, nome, mime]...], document => ...|null]. */
    public static function payload(array $pub): array
    {
        $load = function (array $f): array {
            $bytes = @file_get_contents(Config::storageRoot() . '/' . $f['path']);
            if ($bytes === false) {
                throw new RuntimeException('File della pubblicazione mancante: ' . $f['name']);
            }
            return [$bytes, $f['name'], $f['mime']];
        };
        return [
            'media' => array_map($load, $pub['media']),
            'document' => $pub['document'] ? $load($pub['document']) : null,
        ];
    }

    public static function setReviewMessage(int $id, ?int $messageId): void
    {
        Database::get()->prepare('UPDATE publications SET review_message_id = :m WHERE id = :id')
            ->execute([':m' => $messageId, ':id' => $id]);
    }

    /**
     * Presa in carico atomica di una pubblicazione in attesa (stato
     * transitorio 'sending'): due approvazioni simultanee — doppio clic,
     * due persone — non possono pubblicare due volte. false se non era più
     * in attesa.
     */
    public static function claim(int $id): bool
    {
        // decided_at fa da orario della presa in carico finché lo stato è
        // 'sending': una presa rimasta a metà da più di STALE_CLAIM (processo
        // interrotto durante l'invio) si può riprendere.
        $stmt = Database::get()->prepare(
            "UPDATE publications SET status = 'sending', decided_at = datetime('now')
             WHERE id = :id AND (status = 'pending' OR (status = 'sending' AND decided_at < datetime('now', :stale)))"
        );
        $stmt->execute([':id' => $id, ':stale' => self::STALE_CLAIM]);
        return $stmt->rowCount() === 1;
    }

    /** Invio non riuscito: torna in attesa. */
    public static function release(int $id): void
    {
        Database::get()->prepare("UPDATE publications SET status = 'pending', decided_at = NULL WHERE id = :id AND status = 'sending'")->execute([':id' => $id]);
    }

    /** Approvata e pubblicata (con la didascalia eventualmente corretta). */
    public static function markPublished(int $id, string $caption): void
    {
        Database::get()->prepare(
            "UPDATE publications SET status = 'published', caption = :c, decided_at = datetime('now') WHERE id = :id"
        )->execute([':c' => $caption, ':id' => $id]);
    }

    /** Scartata: le immagini non servono più e vengono eliminate. */
    public static function markRejected(int $id): void
    {
        $pub = self::find($id);
        if ($pub) {
            self::deleteFiles($pub);
        }
        Database::get()->prepare(
            "UPDATE publications SET status = 'rejected', media_json = '[]', document_json = NULL, decided_at = datetime('now') WHERE id = :id"
        )->execute([':id' => $id]);
    }

    public static function setError(int $id, string $error): void
    {
        Database::get()->prepare('UPDATE publications SET error = :e WHERE id = :id')->execute([':e' => $error, ':id' => $id]);
    }

    /**
     * Invia un contenuto a una chat: una foto o un album e, in risposta,
     * l'eventuale file a piena risoluzione. Solo il primo invio — il
     * contenuto vero e proprio — è decisivo: se fallisce, eccezione e nulla
     * è uscito; se fallisce dopo (il file), il contenuto è già pubblico, e il
     * chiamante deve trattarlo come pubblicato (un nuovo tentativo lo
     * duplicherebbe), con un avviso.
     * @return array{first:?int, warnings:string[]}
     */
    public static function send(TelegramClient $client, array $payload, string $caption, ?int $replyTo = null): array
    {
        $media = $payload['media'];
        if (count($media) > 1) {
            $messages = $client->sendMediaGroup($media, $caption, $replyTo);
            $first = isset($messages[0]['message_id']) ? (int) $messages[0]['message_id'] : null;
        } else {
            [$bytes, $name, $mime] = $media[0];
            $message = $client->sendPhoto($bytes, $caption, $name, $mime, [], $replyTo);
            $first = isset($message['message_id']) ? (int) $message['message_id'] : null;
        }
        $warnings = [];
        if ($payload['document']) {
            [$bytes, $name, $mime] = $payload['document'];
            try {
                $client->sendDocument($bytes, $name, $mime, '', $first);
            } catch (Throwable $e) {
                $warnings[] = 'il file a piena risoluzione non è stato inviato (' . $e->getMessage() . ')';
            }
        }
        return ['first' => $first, 'warnings' => $warnings];
    }

    /** Link alla coda nella piattaforma, se l'indirizzo pubblico è configurato. */
    public static function queueUrl(int $id): ?string
    {
        $base = rtrim(trim((string) (AppSettings::all()['public_base_url'] ?? '')), '/');
        return preg_match('#^https?://#i', $base) ? $base . '/publications.php#pub-' . $id : null;
    }

    private static function deleteFiles(array $pub): void
    {
        foreach (array_merge($pub['media'], $pub['document'] ? [$pub['document']] : []) as $f) {
            if (!empty($f['path']) && strpos($f['path'], '..') === false && strpos($f['path'], self::DIR . '/') === 0) {
                @unlink(Config::storageRoot() . '/' . $f['path']);
            }
        }
    }

    private static function decode(array $row): array
    {
        $row['media'] = json_decode($row['media_json'] ?? '[]', true) ?: [];
        $row['document'] = !empty($row['document_json']) ? json_decode($row['document_json'], true) : null;
        unset($row['media_json'], $row['document_json']);
        return $row;
    }
}
