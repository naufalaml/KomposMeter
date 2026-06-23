#include <WiFiManager.h>
#include <Wire.h>
#include <Adafruit_ADS1X15.h>
#include <LiquidCrystal_I2C.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecureBearSSL.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <ArduinoJson.h>
#include <EEPROM.h>
#define PIN_DS18B20     D3       // DS18B20 Data
#define PIN_RESET_BTN   D4       // Tombol Reset WiFi (INPUT_PULLUP)
#define PIN_LED         LED_BUILTIN  // LED bawaan WeMos (active LOW)

// ═══════════════════════════════════════════════════════════════
//  KONFIGURASI SISTEM
// ═══════════════════════════════════════════════════════════════
const char* DEVICE_ID    = "WEMOS-D1-001";
const char* AP_NAME      = "KmposMtr Setup";
const char* AP_PASSWORD  = "kompos1234";
const char* SERVER_URL   = "https://zipultekno.shop/api/receive.php";

const unsigned long INTERVAL_BACA_MS = 120000UL;  // baca sensor tiap 2 menit
const unsigned long TIMEOUT_WIFI_MS  = 180000UL;  // portal WiFi timeout 3 menit

// ═══════════════════════════════════════════════════════════════
//  THRESHOLD KEMATANGAN (LOGIKA PARALEL)
// ═══════════════════════════════════════════════════════════════
const float SUHU_MATANG_MAX   = 35.0;  // °C  Suhu adem di bawah ini = potensi matang
const float KELEMBAPAN_MIN    = 40.0;  // %   Di bawah ini = kering (indikator matang)
const float AMONIA_MATANG_MAX = 25.0;  // ppm Amonia di bawah ini = bau hilang (matang)

// ═══════════════════════════════════════════════════════════════
//  KALIBRASI SENSOR
// ═══════════════════════════════════════════════════════════════
// -- Kalibrasi Suhu --
const float KALIBRASI_SUHU_OFFSET = -2.9; // Hasil sensor dikurangi 2.9 derajat Celcius

// -- Soil Moisture --
const int   SOIL_DRY_ADC  = 19650;  // ADC saat sensor di udara (kering)
const int   SOIL_WET_ADC  =  8000;  // ADC saat sensor tercelup air (basah penuh)

// -- MQ-135 Amonia (Sensitivitas Tinggi Linear) --
const float MQ135_VCC          = 3.3;   
const float MQ135_VOLT_CLEAN   = 0.15;  // Tegangan saat udara bersih (dari log alat kamu)
const int   MQ135_PPM_CLEAN    = 1;     // Nilai default awal saat udara bersih
const float FAKTOR_SENSITIF    = 450.0; // Semakin besar, semakin sensitif mendeteksi bau aroma

// ═══════════════════════════════════════════════════════════════
//  OBJEK LIBRARY & VARIABEL GLOBAL
// ═══════════════════════════════════════════════════════════════
OneWire           oneWire(PIN_DS18B20);
DallasTemperature ds18b20(&oneWire);
LiquidCrystal_I2C lcd(0x27, 16, 2);  
Adafruit_ADS1115  ads;

std::unique_ptr<BearSSL::WiFiClientSecure> wifiClientSecure;

unsigned long lastReadTime  = 0;
bool          wifiConnected = false;
int           sendFailCount = 0;

struct SensorData {
  float  suhu;
  float  kelembapan;
  int    amonia_ppm; 
  int    raw_soil;
  int    raw_amonia;
  float  voltage_mq;
  String status_kematangan;
  String fase_fermentasi;
  int    estimasi_hari;
};
float nilaiSuhu = 0.0;
float nilaiKelembapan = 0.0;
float nilaiAmonia = 0.0;
// ════════════════════════════════════════════════════════════════
//  SETUP
// ════════════════════════════════════════════════════════════════
void setup() {
  Serial.begin(115200);
  delay(200);

  Wire.begin(D1, D2);
  lcd.init();
  lcd.backlight();
  lcdTulis("KomposMeter v3", "Inisialisasi...");
  delay(1500);

  if (!ads.begin()) {
    Serial.println(F("[ADS1115] GAGAL init!"));
    lcdTulis("ADS1115 ERROR", "Cek Kabel I2C!");
    while (true) { blinkLED(3, 200); delay(1000); }
  }
  ads.setGain(GAIN_ONE); // gain ±4.096V -> 1 bit = 0.125 mV

  ds18b20.begin();
  ds18b20.setResolution(11);

  pinMode(PIN_LED,       OUTPUT);
  pinMode(PIN_RESET_BTN, INPUT_PULLUP);
  digitalWrite(PIN_LED,   HIGH);  

  Serial.println(F("\n========================================"));
  Serial.println(F("  KomposMeter IoT — WeMos D1 Mini"));
  Serial.println(F("  Pendeteksi Kematangan Kohe Kambing"));
  Serial.println(F("========================================"));

  setupWiFiManager();

  wifiClientSecure.reset(new BearSSL::WiFiClientSecure);
  wifiClientSecure->setInsecure();  

  if (WiFi.status() == WL_CONNECTED) {
    wifiConnected = true;
    Serial.print(F("[WiFi] Terhubung. IP: "));
    Serial.println(WiFi.localIP());
    lcdTulis("WiFi Terhubung!", WiFi.localIP().toString());
    blinkLED(3, 100);
    delay(1500);
  } else {
    Serial.println(F("[WiFi] Offline mode."));
    lcdTulis("Mode Offline", "Data lokal saja");
    delay(1500);
  }
}

// ════════════════════════════════════════════════════════════════
//  LOOP
// ════════════════════════════════════════════════════════════════
void loop() {
  checkResetButton();

  unsigned long now = millis();
  if (now - lastReadTime >= INTERVAL_BACA_MS || lastReadTime == 0) {
    lastReadTime = now;

    SensorData data = bacaSensor();
    cetakSerial(data);
    tampilLCD(data);

    if (WiFi.status() == WL_CONNECTED) {
      Serial.println(F("\n[HTTPS] Memulai pengiriman JSON ke server..."));
      bool ok = kirimKeServer(data);
      if (ok) {
        Serial.println(F("[HTTPS] BERHASIL! Data sukses diterima oleh server."));
        sendFailCount = 0;
        blinkLED(2, 80);
      } else {
        Serial.println(F("[HTTPS] GAGAL! Respon server tidak valid atau koneksi bermasalah."));
        sendFailCount++;
        if (sendFailCount >= 5) {
          Serial.println(F("[HTTPS] Gagal 5 kali berturut-turut. Me-restart koneksi WiFi..."));
          WiFi.disconnect();
          delay(2000);
          WiFi.reconnect();
          sendFailCount = 0;
        }
      }
    } else {
      Serial.println(F("[WIFI] Putus! Mencoba menghubungkan kembali..."));
      WiFi.reconnect();
    }
  }
}

// ════════════════════════════════════════════════════════════════
//  BACA SENSOR
// ════════════════════════════════════════════════════════════════
SensorData bacaSensor() {
  SensorData d;

  // ── 1. Baca DS18B20 Suhu (Dikalibrasi -3 Derajat) ─────────────
  ds18b20.requestTemperatures();
  delay(200);  
  float rawSuhu = ds18b20.getTempCByIndex(0);
  
  if (rawSuhu == DEVICE_DISCONNECTED_C || rawSuhu < -50.0) {
    d.suhu = -99.0;
  } else {
    d.suhu = rawSuhu + KALIBRASI_SUHU_OFFSET; 
  }

  // ── 2. Baca Kelembapan Soil ───────────────────────────────────
  long soilSum = 0;
  for (int i = 0; i < 8; i++) {
    soilSum += ads.readADC_SingleEnded(1);
    delay(10);
  }
  d.raw_soil = soilSum / 8;
  float kel = map(d.raw_soil, SOIL_DRY_ADC, SOIL_WET_ADC, 0, 100);
  d.kelembapan = constrain(kel, 0.0f, 100.0f);

  // ── 3. Baca MQ-135 Amonia (Sistem Sensitif Berbasis Baseline) ──
  // ── 3. Baca MQ-135 Amonia (Versi Stabil & Responsif) ──────────
long mqSum = 0;

for (int i = 0; i < 15; i++) {
  mqSum += ads.readADC_SingleEnded(0);
  delay(5);
}

d.raw_amonia = mqSum / 15;

// Konversi ADC → Volt
d.voltage_mq = d.raw_amonia * 0.125f / 1000.0f;

// ===============================
// FILTER SMOOTHING
// ===============================
static float filteredVolt = 0.16;

filteredVolt =
  (filteredVolt * 0.85f) +
  (d.voltage_mq * 0.15f);

// ===============================
// HITUNG PPM
// ===============================
float diffVolt = filteredVolt - MQ135_VOLT_CLEAN;

// Jika udara sangat bersih
if (diffVolt <= 0.002f) {

  d.amonia_ppm = 1;

} else {

  // Naik bertahap saat ada hembusan / bau
  d.amonia_ppm =
      1 +
      (diffVolt * 350.0f);

}

// Batas nilai
d.amonia_ppm = constrain(d.amonia_ppm, 1, 999);

  tentukanStatus(d);
  return d;
}

// ════════════════════════════════════════════════════════════════
//  TENTUKAN STATUS KEMATANGAN (LOGIKA HUKUM REAL SEBAB-AKIBAT PUPUK)
// ════════════════════════════════════════════════════════════════
void tentukanStatus(SensorData &d) {
  // Syarat 1: Suhu harus adem / normal (Fermentasi selesai)
  bool suhuAdem = (d.suhu > 0.0f && d.suhu <= SUHU_MATANG_MAX);
  
  // Syarat 2: Kelembapan harus rendah (Pupuk sudah mengering dan matang)
  bool kelembapanRendah = (d.kelembapan < KELEMBAPAN_MIN);
  
  // Syarat 3: Kadar Amonia harus rendah (Bau menyengat sudah hilang)
  bool amoniaAman = (d.amonia_ppm <= (int)AMONIA_MATANG_MAX);

  // KRITERIA KEMATANGAN MUTLAK: Ketiga syarat di atas harus terpenuhi bersamaan
  if (suhuAdem && kelembapanRendah && amoniaAman) {
    d.status_kematangan = "Matang";
    d.estimasi_hari     = 0;
  } 
  // Jika salah satu saja dari parameter di atas masih tinggi/melanggar, otomatis BELUM MATANG
  else {
    d.status_kematangan = "Mentah";
    
    // Perhitungan dinamis sisa hari berdasarkan parameter yang paling buruk
    if (d.amonia_ppm > (int)AMONIA_MATANG_MAX) {
      float sisa = ((float)d.amonia_ppm - AMONIA_MATANG_MAX) / 1.5f;
      d.estimasi_hari = (int)constrain(sisa, 3.0f, 60.0f);
    } else if (d.suhu > SUHU_MATANG_MAX) {
      d.estimasi_hari = 14; // Jika suhu masih panas, berarti masih fermentasi aktif
    } else {
      d.estimasi_hari = 5;  // Jika hanya faktor kadar air/kelembapan yang masih tinggi
    }
  }

  // Melengkapi data fase suhu untuk rekam jejak di database web/server
  if (d.suhu >= 50.0f)      d.fase_fermentasi = "Termofilik";
  else if (d.suhu >= 35.0f) d.fase_fermentasi = "Mesofilik";
  else                      d.fase_fermentasi = "Pematangan";
}

// ════════════════════════════════════════════════════════════════
//  TAMPIL LCD 16x2
// ════════════════════════════════════════════════════════════════
void tampilLCD(const SensorData &d) {
  lcd.clear();

  // Baris 0: T:XX.X°C  K:XX%
  lcd.setCursor(0, 0);
  lcd.print(F("T:"));
  if (d.suhu == -99.0f) {
    lcd.print(F("ERR "));
  } else {
    lcd.print(d.suhu, 1);
    lcd.print(F("C "));
  }
  lcd.print(F("K:"));
  lcd.print((int)d.kelembapan);
  lcd.print(F("%"));

  // Baris 1: Blm Matang   1ppm / Matang       15ppm
  lcd.setCursor(0, 1);
  lcd.print(d.status_kematangan);
  
  // Set posisi teks PPM statis di ujung kanan (kolom ke-10) agar tidak saling tabrak
  lcd.setCursor(10, 1);
  if (d.amonia_ppm < 10)       lcd.print(F("  "));
  else if (d.amonia_ppm < 100) lcd.print(F(" "));
  
  lcd.print(d.amonia_ppm);
  lcd.print(F("ppm")); // Singkatan dari 'ppm' agar hemat ruang grid LCD 16x2
}

// ════════════════════════════════════════════════════════════════
//  KIRIM DATA KE SERVER (HTTPS POST JSON)
// ════════════════════════════════════════════════════════════════
bool kirimKeServer(const SensorData &d) {
  if (WiFi.status() != WL_CONNECTED) return false;

  HTTPClient http;
  http.begin(*wifiClientSecure, SERVER_URL);
  http.addHeader(F("Content-Type"), F("application/json"));
  http.addHeader(F("X-Device-ID"), DEVICE_ID);
  http.setTimeout(12000);

  StaticJsonDocument<320> doc;
  doc[F("device_id")]         = DEVICE_ID;
  doc[F("suhu")]              = serialized(String(d.suhu, 2));
  doc[F("kelembapan")]        = serialized(String(d.kelembapan, 2));
  doc[F("amonia")]            = d.amonia_ppm; 
  doc[F("voltage_mq")]        = serialized(String(d.voltage_mq, 4));
  doc[F("raw_soil")]          = d.raw_soil;
  doc[F("raw_amonia")]        = d.raw_amonia;
  doc[F("status_kematangan")] = d.status_kematangan;
  doc[F("fase_fermentasi")]   = d.fase_fermentasi;
  doc[F("estimasi_hari")]     = d.estimasi_hari;
  doc[F("rssi")]              = WiFi.RSSI();
  doc[F("ip")]                = WiFi.localIP().toString();

  String payload;
  serializeJson(doc, payload);

  int code = http.POST(payload);
  Serial.printf("[HTTPS] HTTP Response Code: %d\n", code);
  
  if (code > 0) {
    String response = http.getString();
    Serial.print(F("[HTTPS] Balasan dari Server: "));
    Serial.println(response);
  } else {
    Serial.printf("[HTTPS] Koneksi gagal! Error: %s\n", http.errorToString(code).c_str());
  }
  
  http.end();
  return (code == 200);
}

// ════════════════════════════════════════════════════════════════
//  CETAK SERIAL MONITOR
// ════════════════════════════════════════════════════════════════
void cetakSerial(const SensorData &d) {
  Serial.println(F("\n──────────────────────────────────────────"));
  Serial.printf_P(PSTR("  Suhu         : %.2f °C\n"),   d.suhu);
  Serial.printf_P(PSTR("  Kelembapan   : %.2f %%\n"),   d.kelembapan);
  Serial.printf_P(PSTR("  Amonia (ppm) : %d ppm\n"),    d.amonia_ppm); 
  Serial.printf_P(PSTR("  Tegangan MQ  : %.4f V\n"),    d.voltage_mq);
  Serial.printf_P(PSTR("  Raw MQ   ADC : %d\n"),         d.raw_amonia);
  Serial.printf_P(PSTR("  Status       : %s\n"),         d.status_kematangan.c_str());
  Serial.printf_P(PSTR("  Sisa Hari    : %d hari\n"),   d.estimasi_hari);
  Serial.println(F("──────────────────────────────────────────"));
}

// ════════════════════════════════════════════════════════════════
//  WIFI CONFIG MANAGER SETUP
// ════════════════════════════════════════════════════════════════
void setupWiFiManager() {
  WiFiManager wm;
  //wm.resetSettings(); 
  wm.setDebugOutput(false);

  if (digitalRead(PIN_RESET_BTN) == LOW) {
    wm.resetSettings();
    delay(1500);
  }

  wm.setAPStaticIPConfig(IPAddress(192,168,4,1), IPAddress(192,168,4,1), IPAddress(255,255,255,0));
  wm.setConnectTimeout(20);
  wm.setConfigPortalTimeout(TIMEOUT_WIFI_MS / 1000);

  lcdTulis("Koneksi WiFi...", AP_NAME);
  digitalWrite(PIN_LED, LOW);  

  if (!wm.autoConnect(AP_NAME, AP_PASSWORD)) {
    ESP.restart();
  }
  digitalWrite(PIN_LED, HIGH);
}

void checkResetButton() {
  static unsigned long btnStart = 0;
  if (digitalRead(PIN_RESET_BTN) == LOW) {
    if (btnStart == 0) btnStart = millis();
    if (millis() - btnStart >= 5000UL) {
      lcdTulis("Reset WiFi...", "Restart...");
      blinkLED(5, 100);
      WiFiManager wm;
      wm.resetSettings();
      delay(500);
      ESP.restart();
    }
  } else {
    btnStart = 0;
  }
}

void blinkLED(int times, int ms) {
  for (int i = 0; i < times; i++) {
    digitalWrite(PIN_LED, LOW);
    delay(ms);
    digitalWrite(PIN_LED, HIGH);
    delay(ms);
  }
}

void lcdTulis(const char* baris0, const char* baris1) {
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print(baris0);
  lcd.setCursor(0, 1); lcd.print(baris1);
}
void lcdTulis(const char* baris0, String baris1) {
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print(baris0);
  lcd.setCursor(0, 1); lcd.print(baris1);
}
void kirimDataIoT() {
  if (WiFi.status() == WL_CONNECTED) {
    WiFiClient client;
    HTTPClient http;

    // Alamat URL API tempat WeMos mengirimkan data
    http.begin(client, "http://alamat_ip_server_kamu/api/save_data.php"); // Sesuaikan URL API-mu
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");

    // Menyiapkan data POST (Sesuaikan dengan nama variabel di file API/Input database)
    String postData = "suhu=" + String(nilaiSuhu) + 
                      "&kelembapan=" + String(nilaiKelembapan) + 
                      "&amonia=" + String(nilaiAmonia) +
                      "&ip_address=" + WiFi.localIP().toString();

    // ─── TAMPILKAN KE SERIAL MONITOR SEBELUM KIRIM ─────────────────
    Serial.println("\n--- MEMULAI PENGIRIMAN DATA ---");
    Serial.print("Menghubungkan ke Server... ");
    
    // Melakukan HTTP POST
    int httpResponseCode = http.POST(postData);

    // ─── TAMPILKAN RESPON SERVER KE SERIAL MONITOR ─────────────────
    if (httpResponseCode > 0) {
      Serial.println("[BERHASIL]");
      Serial.print("HTTP Respon Code: ");
      Serial.println(httpResponseCode); // Biasanya 200 artinya sukses dimasukkan
      
      String payload = http.getString();
      Serial.print("Balasan dari Server: ");
      Serial.println(payload); // Melihat teks balasan dari file PHP API
    } else {
      Serial.println("[GAGAL]");
      Serial.print("Error Code: ");
      Serial.println(httpResponseCode); // Jika minus, berarti ada masalah koneksi/IP salah
    }
    
    Serial.println("--------------------------------\n");
    http.end(); // Tutup koneksi
  } else {
    Serial.println("Koneksi WiFi Terputus. Gagal mengirim data.");
  }
}