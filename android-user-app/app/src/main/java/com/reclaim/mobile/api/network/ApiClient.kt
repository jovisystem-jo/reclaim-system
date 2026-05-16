package com.reclaim.mobile.api.network

import android.content.Context
import com.reclaim.mobile.storage.settings.AppSettingsManager
import com.reclaim.mobile.storage.session.SessionManager
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

object ApiClient {

    @Volatile
    private var service: ApiService? = null
    @Volatile
    private var activeBaseUrl: String? = null

    fun service(context: Context): ApiService {
        val appContext = context.applicationContext
        val requestedBaseUrl = AppSettingsManager(appContext).getApiBaseUrl()
        val cachedService = service

        if (cachedService != null && activeBaseUrl == requestedBaseUrl) {
            return cachedService
        }

        return synchronized(this) {
            val refreshedService = service
            if (refreshedService != null && activeBaseUrl == requestedBaseUrl) {
                refreshedService
            } else {
                buildService(appContext, requestedBaseUrl).also {
                    service = it
                    activeBaseUrl = requestedBaseUrl
                }
            }
        }
    }

    fun reset() {
        synchronized(this) {
            service = null
            activeBaseUrl = null
        }
    }

    private fun buildService(context: Context, baseUrl: String): ApiService {
        val sessionManager = SessionManager(context.applicationContext)
        val logging = HttpLoggingInterceptor().apply {
            level = HttpLoggingInterceptor.Level.BASIC
        }

        val client = OkHttpClient.Builder()
            .addInterceptor(AuthInterceptor(sessionManager))
            .addInterceptor(logging)
            .connectTimeout(30, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .build()

        return Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(ApiService::class.java)
    }
}
