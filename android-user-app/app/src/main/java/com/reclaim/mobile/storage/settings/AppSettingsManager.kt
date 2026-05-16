package com.reclaim.mobile.storage.settings

import android.content.Context
import android.net.Uri
import com.reclaim.mobile.BuildConfig

class AppSettingsManager(context: Context) {

    private val preferences = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    fun getApiBaseUrl(): String {
        val savedValue = preferences.getString(KEY_API_BASE_URL, null)
        return normalizeApiBaseUrl(savedValue ?: BuildConfig.API_BASE_URL)
    }

    fun saveApiBaseUrl(rawUrl: String): String {
        val normalized = normalizeApiBaseUrl(rawUrl)
        preferences.edit()
            .putString(KEY_API_BASE_URL, normalized)
            .apply()
        return normalized
    }

    companion object {
        private const val PREFS_NAME = "reclaim_app_settings"
        private const val KEY_API_BASE_URL = "api_base_url"

        fun normalizeApiBaseUrl(rawUrl: String): String {
            var value = rawUrl.trim()
            require(value.isNotBlank()) { "Server URL is required." }

            if (!value.contains("://")) {
                value = "http://$value"
            }

            val sanitized = value.trimEnd('/')
            val parsed = Uri.parse(sanitized)
            val path = parsed.path.orEmpty().trimEnd('/')

            return when {
                path.isBlank() -> "$sanitized/reclaim-system/api/mobile/"
                path.endsWith("/reclaim-system") -> "$sanitized/api/mobile/"
                path.endsWith("/reclaim-system/api/mobile") -> "$sanitized/"
                path.endsWith("/api/mobile") -> "$sanitized/"
                else -> "$sanitized/"
            }
        }
    }
}
