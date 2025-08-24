
--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `cbav_hud_analyses`
--
ALTER TABLE `cbav_hud_analyses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_snapshot` (`snapshot_id`),
  ADD KEY `idx_symbol_tf_ts` (`symbol`,`tf`,`ts`),
  ADD KEY `k_sym_tf_ts` (`sym`,`tf`,`ts`);

--
-- Индексы таблицы `cbav_hud_snapshots`
--
ALTER TABLE `cbav_hud_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ts_symbol_tf` (`ts`,`symbol`,`tf`),
  ADD KEY `idx_received_at` (`received_at`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `cbav_hud_analyses`
--
ALTER TABLE `cbav_hud_analyses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3332;

--
-- AUTO_INCREMENT для таблицы `cbav_hud_snapshots`
--
ALTER TABLE `cbav_hud_snapshots`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=433;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `cbav_hud_analyses`
--
ALTER TABLE `cbav_hud_analyses`
  ADD CONSTRAINT `fk_cbav_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `cbav_hud_snapshots` (`id`) ON DELETE CASCADE;
