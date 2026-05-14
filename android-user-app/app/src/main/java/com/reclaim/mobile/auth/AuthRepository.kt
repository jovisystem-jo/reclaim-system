package com.reclaim.mobile.auth

import android.content.Context
import com.reclaim.mobile.api.network.ApiClient
import com.reclaim.mobile.models.AuthData
import com.reclaim.mobile.storage.session.SessionManager

class AuthRepository(private val context: Context) {
    private val api = ApiClient.service(context)
    private val session = SessionManager(context)

    suspend fun login(email: String, password: String): Result<AuthData> = runCatching {
        val response = api.login(email, password)
        if (!response.success || response.data == null) {
            throw IllegalStateException(response.message)
        }
        session.saveAuth(response.data.token, response.data.user)
        response.data
    }

    suspend fun register(
        name: String,
        email: String,
        username: String,
        password: String,
        confirmPassword: String,
        studentStaffId: String,
        department: String,
        phone: String
    ): Result<AuthData> = runCatching {
        val response = api.register(name, email, username, password, confirmPassword, studentStaffId, department, phone)
        if (!response.success || response.data == null) {
            throw IllegalStateException(response.message)
        }
        session.saveAuth(response.data.token, response.data.user)
        response.data
    }

    suspend fun logout(): Result<Unit> = runCatching {
        api.logout()
    }.map {
        session.clear()
    }.recoverCatching {
        session.clear()
        Unit
    }
}
