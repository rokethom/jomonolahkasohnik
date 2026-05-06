<div class="mt-6 rounded-2xl border border-slate-700/60 bg-slate-950/70 p-5 text-slate-100">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-lg font-bold">Flow Setoran Driver</h3>
            <p class="mt-1 text-sm text-slate-400">Readonly sesuai logic backend saat ini.</p>
        </div>
        <span class="rounded-full bg-amber-500/15 px-3 py-1 text-xs font-bold text-amber-300">Auto generated per bulan</span>
    </div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-slate-800 bg-slate-900/80 p-4">
            <strong class="text-emerald-300">1. Generate</strong>
            <p class="mt-2 text-sm text-slate-300">Data setoran dibuat/di-update saat driver membuka bootstrap, halaman finance, atau performance.</p>
        </div>
        <div class="rounded-xl border border-slate-800 bg-slate-900/80 p-4">
            <strong class="text-emerald-300">2. Hitung Handle</strong>
            <p class="mt-2 text-sm text-slate-300">Order COMPLETED dijumlah dari service charge: tanggal 1-15 masuk Handle Day 15, tanggal 16-akhir bulan masuk Handle Day 30.</p>
        </div>
        <div class="rounded-xl border border-slate-800 bg-slate-900/80 p-4">
            <strong class="text-emerald-300">3. Tambahan</strong>
            <p class="mt-2 text-sm text-slate-300">Bansos mengikuti area cabang. BPJS Rp 20.000 aktif jika subtotal kurang dari Rp 30.000. BPJS JHT Rp 20.000 jika driver mengaktifkannya.</p>
        </div>
        <div class="rounded-xl border border-slate-800 bg-slate-900/80 p-4">
            <strong class="text-emerald-300">4. Due & Suspend</strong>
            <p class="mt-2 text-sm text-slate-300">Due date otomatis tanggal 20. Deposit unpaid lewat tanggal 20 akan memicu suspend setoran: "Belum bayar setoran lewat tanggal 20".</p>
        </div>
    </div>

    <div class="mt-4 rounded-xl border border-sky-500/20 bg-sky-500/10 p-4 text-sm text-sky-100">
        <strong>Formula:</strong>
        Total = Handle Day 15 + Handle Day 30 + Bansos + BPJS + BPJS JHT. Status otomatis paid jika total <= 0, selain itu unpaid.
    </div>
</div>
